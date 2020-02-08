<?php
	
class Actionnetwork_Sync {
	
	private $processStartTime = 0;
	private $nestingLevel = 0;
	private $endpoints = array( 'petitions', 'events', 'fundraising_pages', 'advocacy_campaigns', 'forms' );
	private $groups = [];
	private $db;
	public $status;
	public $inserted;
	public $deleted;
	public $updated;
	
	function __construct() {
		global $wpdb;
		$this->db = $wpdb;
		$groups_table_name = $wpdb->prefix . 'actionnetwork_groups';
		$groups_sql = "SELECT * FROM $groups_table_name;";

		$group_array = $wpdb->get_results($groups_sql, ARRAY_A);
		foreach($group_array as $group){
			$this->groups[] = new ActionNetworkGroup($group['group_id'], $group['api_key'], $group['name']);
		}
		$this->processStartTime = time();
		$this->status = new Actionnetwork_Sync_Status();
		$this->cleanQueue();

	}
	
	function init() {

		// error_log( "Actionnetwork_Sync::init called", 0 );

		// mark all existing API-synced actions for deletion
		// (any that are still synced will be un-marked)
		$this->db->query("UPDATE {$this->db->prefix}actionnetwork_actions SET enabled=-1 WHERE an_id != ''");
		
		// load actions from Action Network into the queue
		foreach($this->groups as $group){
			foreach ($this->endpoints as $endpoint) {
				$resources = $group->traverseFullCollection( $endpoint );
				foreach($resources as $resource){
					if($resource !== null){
						$this->addToQueue($resource, $group->id, $endpoint);
					}
				}
			}
		}

	}
	
	function addToQueue( $resource, $group_id, $endpoint, $index=0, $total=0 ) {
		$this->db->insert(
			$this->db->prefix.'actionnetwork_queue',
			array (
				'resource' => serialize($resource),
				'endpoint' => $endpoint,
				'g_id' => $group_id,
				'processed' => 0,
			)
		);

		// error_log( "Actionnetwork_Sync::addToQueue called; endpoint: $endpoint, index: $index, total: $total", 0 );
	}

	private function updateQueueStatus() {
		$this->status->update_status();
	}
	
	public function getQueueStatus() {
		return $this->status->get_status();
	}
	
	function processQueue() {
		
		// check queue status

		// error_log( "Actionnetwork_Sync::processQueue called. Status:\n\n".print_r($status,1)."\n\n", 0 );

		$this->status->update_status();
		if ($this->status->type === 'empty') { return; }
		if ($this->status->type === 'complete') {
			cleanQueue();
			wp_die();
			return;
		} 
		
		// check memory usage
		$start_new_process = false;
		$memory_limit = $this->get_memory_limit() * 0.9;
		$current_memory = memory_get_usage( true );
		if ( $current_memory >= $memory_limit ) { $start_new_process = true; }
		
		// check nesting level
		if ($this->nestingLevel > 100) {
			$start_new_process = true;
		}
		
		// check process time
		$time_elapsed = time() - $this->processStartTime;
		if ( $time_elapsed > 20 ) { $start_new_process = true; }
		
		// if over 90% of memory or 20 seconds, use ajax to start a new process
		// and pass updated and inserted variables
		if ($start_new_process) {
			
			$ajax_url = admin_url( 'admin-ajax.php' );
			$ajax_url = "http://localhost/wp-admin/admin-ajax.php";

			// since we're making this call from the server, we can't use a nonce
			$timeint = time() / mt_rand( 1, 10 ) * mt_rand( 1, 10 );
			$timestr = (string) $timeint;
			$token = md5( $timestr );
			update_option( 'actionnetwork_ajax_token', $token );

			$body = array(
				'action' => 'actionnetwork_process_queue',
				'queue_action' => 'continue',
				'updated' => $this->status->updated_action_count,
				'inserted' => $this->status->inserted_action_count,
				'deleted' => $this->status->deleted_action_count,
				'token' => $token,
			);
			$args = array( 'body' => $body );
			
			// error_log( "Actionnetwork_Sync::processQueue trying to start new process, making ajax call to $ajax_url with following args:\n\n" . print_r( $args, 1) . "\n\nActionnetwork_Sync's current state:\n\n" . print_r( $this, 1), 0 );
			
			wp_remote_post( $ajax_url, $args );
			wp_die();
			return;
		}
		
		// process the next resource
		$this->processResource();
		
		// call processQueue to check queue and process status before processing the next resource
		$this->nestingLevel++;
		$this->processQueue();
	}
	
	protected function get_memory_limit() {
		if ( function_exists( 'ini_get' ) ) {
			$memory_limit = ini_get( 'memory_limit' );
		} else {
			// Sensible default.
			$memory_limit = '128M';
		}
		if ( ! $memory_limit || -1 === $memory_limit ) {
			// Unlimited, set to 32GB.
			$memory_limit = '32000M';
		}
		return intval( $memory_limit ) * 1024 * 1024;
	}
	
	function processResource() {
		global $wpdb;
		
		// get a resource out of the database
		$result = $wpdb->get_row( "SELECT * FROM ".$wpdb->prefix."actionnetwork_queue WHERE processed = 0 LIMIT 0,1", ARRAY_A );
		$resource = unserialize($result['resource']);
		$resource_id = $result['resource_id'];
		$endpoint = $result['endpoint'];
		
		$action = new ActionNetworkAction($resource);
	
		// set $data['enabled'] to 0 if:
		// * action_network:hidden is true
		// * status is "cancelled"
		// * event has a start_date that is past


		// check if action exists in database
		// if it does, we don't need to get embed codes, because those never change
		$sql = "SELECT COUNT(*) FROM {$wpdb->prefix}actionnetwork_actions WHERE an_id='{$action->an_id}'";
		$count = $this->db->get_var( $sql );
		if ($count) {
			// if modified_date is more recent than latest api sync, update
			$last_updated = get_option('actionnetwork_cache_timestamp', 0);
			if ($last_updated < $action->modified_date) {
				$this->db->update(
					$this->db->prefix.'actionnetwork_actions',
					$action,
					array( 'an_id' => $action->an_id )
				);
				// $this->updated++;
			
			// otherwise just reset the 'enabled' field (to prevent deletion, and hide events whose start date has passed)
			} else {
				$this->db->update(
					$this->db->prefix.'actionnetwork_actions',
					array( 'enabled' => $action->enabled ),
					array( 'an_id' => $data->an_id )
				);
			}

		} else {
			// if action *doesn't* exist in the database, get embed codes, insert
			$embed_endpoint = $this->getEmbedEndpoint($resource->resource, true);
			$embed_codes = $this->getEmbedCode($embed_endpoint, $action->g_id, true);
			$clean_embed_code = $this->cleanEmbedCodes($embed_codes);
			foreach($clean_embed_code as $i => $value){
				$action->$i = $value;
			}
			$data = (array) $action;
			$this->db->insert(
				$this->db->prefix.'actionnetwork_actions',
				$data
			);
			// $this->inserted++;
		}

		// mark resource as processed
		$this->db->update(
			$this->db->prefix.'actionnetwork_queue',
			array( 'processed' => 1 ),
			array( 'resource_id' => $resource_id )
		);
	}
	
	public function getEmbedEndpoint($action, $array = false) {
		$embed_endpoint = isset($action->_links->{'action_network:embed'}->href) ? $action->_links->{'action_network:embed'}->href : '';
		if (!$embed_endpoint) { return $array ? array() : null; }
		return $embed_endpoint;
	}

	function cleanEmbedCodes($embed_codes_raw) {
		$embed_fields = array(
			'embed_standard_layout_only_styles',
			'embed_full_layout_only_styles',
			'embed_standard_no_styles',
			'embed_full_no_styles',
			'embed_standard_default_styles',
			'embed_full_default_styles',
		);
		foreach ($embed_fields as $embed_field) {
			$embed_codes[$embed_field] = isset($embed_codes_raw[$embed_field]) ? $embed_codes_raw[$embed_field] : '';
		}
		return $embed_codes;
	}
	
	function cleanQueue() {		
		// clear the process queue
		$this->db->query("DELETE FROM {$this->db->prefix}actionnetwork_queue WHERE processed = 1");
		
		// remove all API-synced action that are still marked for deletion
		$this->deleted = $this->db->query("DELETE FROM {$this->db->prefix}actionnetwork_actions WHERE an_id != '' AND enabled=-1");
		
		// update queue status and cache timestamps options
		update_option( 'actionnetwork_cache_timestamp', (int) current_time('timestamp') );
		
		// set an admin notice
		$notices = get_option('actionnetwork_deferred_admin_notices', array());
		$notices['api_sync_completed'] = __(
			/* translators: all %d refer to number of actions inserted, updated, deleted, etc. */
			sprintf( 'Action Network API Sync Completed. %d actions inserted. %d actions updated. %s actions deleted.', $this->inserted, $this->updated, $this->deleted ),
			'actionnetwork'
		);
		update_option('actionnetwork_deferred_admin_notices', $notices);
	}
	public function getEmbedCode($embed_endpoint, $group_id, $array=false){
		$group = new ActionNetworkGroup($group_id);
		$embed_codes = $group->call($embed_endpoint);
		return $array ? (array) $embed_codes : $embed_codes;
	}
	
}
class Actionnetwork_Sync_Status {
	private $type_text = '';
	private $processing_text = '';
	private $db;
	private $actions_table;
	private $queue_table;


	public function __construct(){
		global $wpdb;
		$this->db = $wpdb;
		$this->actions_table = $this->db->prefix . "actionnetwork_actions";
		$this->queue_table = $this->db->prefix . "actionnetwork_queue";
		
	}

	public function get_type(){
		$total_action_count = $this->get_total_action_count();
		$processed_action_count = $this->get_processed_action_count();

		if ($total_action_count === 0) {
			return 'empty';
		} elseif ($total_action_count && ($total_action_count === $processed_action_count)) {
			return 'complete';
		} else {
			return 'processing';
		}
	}

	public function get_total_action_count(){
		$count = (int)$this->db->get_var( "SELECT COUNT(*) FROM $this->queue_table;");
		return $count;
	}

	public function get_processed_action_count(){
		$count = (int)$this->db->get_var( "SELECT COUNT(*) FROM $this->queue_table WHERE processed = 1;");
		return $count;
	}

	public function get_updated_action_count(){
		return $this->updated_action_count; 
	}

	public function get_inserted_action_count(){
		return $this->inserted_action_count;
	}

	public function get_deleted_action_count(){
		$count = (int)$this->db->query("DELETE FROM $this->actions_table WHERE an_id != '' AND enabled=-1;");
		return $count;
	}

	public function get_type_text(){
		return $this->type_text;
	}

	public function get_processing_text(){
		return $this->processing_text;
	}

	public function update_status(){
		$this->type = $this->get_type();
		$processed_action_count = $this->get_processed_action_count();
		$total_action_count = $this->get_total_action_count();
		update_option('actionnetwork_queue_status', $this->type);
		$this->type_text = __("API Sync queue is $this->type ", 'actionnetwork');
		$this->processing_text = __("$processed_action_count of $total_action_count items processed.", 'actionnetwork');
	}
}

Class ActionNetworkAction{
	public $an_id;
	public $g_id;
	public $created_date;
	public $modified_date;
	public $start_date;
	public $browser_url;
	public $title;
	public $name;
	public $description;
	public $location;
	public $enabled;
	public $type;
	public $embed_standard_layout_only_styles;
	public $embed_full_layout_only_styles;
	public $embed_standard_no_styles;
	public $embed_full_no_styles;
	public $embed_standard_default_styles;
	public $embed_full_default_styles;
	public $hidden;
	public $featured_image_url;

	public function __construct($data){
		$this->an_id = $this->getId($data->resource);
		$this->g_id = isset($data->g_id) ? $data->g_id : '';
		$this->created_date = isset($data->resource->created_date) ? strtotime($data->resource->created_date) : null;
		$this->modified_date = isset($data->resource->modified_date) ? strtotime($data->resource->modified_date) : null;
		$this->start_date = isset($data->resource->start_date) ? strtotime($data->resource->start_date) : null;
		$this->browser_url = isset($data->resource->browser_url) ? $data->resource->browser_url : '';
		$this->title = isset($data->resource->title) ? $data->resource->title : '';
		$this->name = isset($data->resource->name) ? $data->resource->name : '';
		$this->description = isset($data->resource->description) ? $data->resource->description : '';
		$this->location = isset($data->resource->location) ? serialize($data->resource->location) : '';
		$this->enabled = 1;
		$this->hidden = $data->resource->{'action_network:hidden'};
		if (isset($data->resource->{'action_network:hidden'}) && ($data->resource->{'action_network:hidden'} == true)) {
			$this->enabled = 0;
		}
		if (isset($data->resource->status) && ($data->resource->status == 'cancelled')) {
			$this->enabled = 0;
		}
		if ($data->resource->start_date && (strtotime($data->resource->start_date) < (int) current_time('timestamp'))) {
			$this->enabled = 0;
		}
	
		// use endpoint (minus pluralizing s) to set $data['type']
		$this->type = substr($data->endpoint,0,strlen($data->endpoint) - 1);
		$this->featured_image_url = isset($data->resource->featured_image_url) && $data->resource->featured_image_url !== "" ? $data->featured_image_url : "";
	}

	public function getId($data){
		if (!isset($data->identifiers) || !is_array($data->identifiers)) { return null; }
		foreach ($data->identifiers as $identifier) {
			if (substr($identifier,0,15) == 'action_network:') { return substr($identifier,15); }
		}
	}
}