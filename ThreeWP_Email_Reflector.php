<?php
/*                                                                                                                                                                                                                                                             
Plugin Name: ThreeWP Email Reflector
Plugin URI: http://mindreantre.se
Description: A mailing list deamon that reflects email from an IMAP account to readers.
Version: 1.18
Author: edward mindreantre
Author URI: http://www.mindreantre.se
Author Email: edward@mindreantre.se
*/
if(preg_match('#' . basename(__FILE__) . '#', $_SERVER['PHP_SELF'])) { die('You are not allowed to call this page directly.'); }

require_once('ThreeWP_Email_Reflector_Base.php');
require_once( 'class_ThreeWP_Email_Reflector_Cron_Options.php' );
require_once( 'class_ThreeWP_Email_Reflector_Process_Options.php' );
require_once( 'class_ThreeWP_Email_Reflector_Settings.php' );

class ThreeWP_Email_Reflector extends ThreeWP_Email_Reflector_Base
{
	protected $site_options = array(
		'cron_minutes' => 5, 
		'collection_connections' => 10,
		'collection_connections_per_server' => 10,
		'curl_follow_location' => false, 
		'database_version' => 17,		// It was supposed to be connected to the version number of the plugin, but now it's just an arbitrary number.
		'enabled' => true,
		'event_key' => AUTH_SALT,
		'items_per_page' => 100,
		'old_age' => 7,
		'priority_decrease_per' => 50,
		'security_all_lists_visible' => true,
		'security_role_to_use' => 'administrator',
		'send_batch_connections' => 1,
		'send_batch_size' => 25,
	);
	
	/**
		Convenience.
		@var		$is_admin
	**/
	private $is_admin;
	
	/**                                          
		Array of access types.
		@var		$access_types
	**/
	private $access_types = array();
	
	/**
		Convenience array: which access types enable editing of lists.
		@var		$access_for_edit
	**/
	private $access_for_edit = array();
		
	public function __construct()
	{
		parent::__construct( __FILE__ );
		add_action( 'wp_loaded', array( &$this, 'wp_loaded' ), 5 );
		add_action( 'admin_menu', array(&$this, 'admin_menu') );
		add_action( 'network_admin_menu', array(&$this, 'admin_menu') );
		add_action( 'threewp_email_reflector_cron', array( &$this, 'cron' ) );

		add_filter( 'threewp_activity_monitor_list_activities', array(&$this, 'threewp_activity_monitor_list_activities') );

		add_filter( 'threewp_email_reflector_admin_edit_get_inputs', array( $this, 'threewp_email_reflector_admin_edit_get_inputs' ), 1 );
		add_filter( 'threewp_email_reflector_admin_edit_get_setting_types', array( $this, 'threewp_email_reflector_admin_edit_get_setting_types' ), 1 );
		add_filter( 'threewp_email_reflector_admin_get_tab_data', array( $this, 'threewp_email_reflector_admin_get_tab_data' ), 1 );
		add_filter( 'threewp_email_reflector_admin_overview_get_list_info', array( $this, 'threewp_email_reflector_admin_overview_get_list_info' ), 10, 2 );
		add_filter( 'threewp_email_reflector_admin_settings_get_inputs', array( $this, 'threewp_email_reflector_admin_settings_get_inputs' ), 1 );
		add_filter( 'threewp_email_reflector_admin_settings_get_setting_types', array( $this, 'threewp_email_reflector_admin_settings_get_setting_types' ), 1 );
		add_filter( 'threewp_email_reflector_discard_message', array( $this, 'threewp_email_reflector_discard_message' ), 100 );	// We're the last one to handle the message.
		add_filter( 'threewp_email_reflector_get_access_types', array( $this, 'threewp_email_reflector_get_access_types' ), 1 );
		add_filter( 'threewp_email_reflector_get_lists', array( $this, 'threewp_email_reflector_get_lists' ) );
		add_filter( 'threewp_email_reflector_get_list_settings', array( $this, 'threewp_email_reflector_get_list_settings' ) );
		add_filter( 'threewp_email_reflector_get_lists_with_settings', array( $this, 'threewp_email_reflector_get_lists_with_settings' ) );
		add_filter( 'threewp_email_reflector_get_log_types', array( $this, 'threewp_email_reflector_get_log_types' ), 1 );
		add_filter( 'threewp_email_reflector_get_option', array( $this, 'threewp_email_reflector_get_option' ) );
		add_filter( 'threewp_email_reflector_get_queue_size', array( $this, 'threewp_email_reflector_get_queue_size' ) );
		add_filter( 'threewp_email_reflector_handle_event', array( $this, 'threewp_email_reflector_handle_event' ) );
		add_filter( 'threewp_email_reflector_log', array( $this, 'threewp_email_reflector_log' ), 10, 2 );
		add_filter( 'threewp_email_reflector_process_list', array( $this, 'threewp_email_reflector_process_list' ) );
		add_filter( 'threewp_email_reflector_update_option', array( $this, 'threewp_email_reflector_update_option' ), 10, 2 );
		add_filter( 'threewp_email_reflector_update_list_setting', array( $this, 'threewp_email_reflector_update_list_setting' ), 10, 3 );
	}
	
	// --------------------------------------------------------------------------------------------
	// ----------------------------------------- Callbacks
	// --------------------------------------------------------------------------------------------
	public function activate()
	{
		parent::activate();

		if ( $this->sql_table_exists($this->wpdb->base_prefix."_3wp_email_reflector_codes") )
		{
			$this->query("RENAME TABLE `".$this->wpdb->base_prefix."_3wp_email_reflector_codes` TO `".$this->wpdb->base_prefix."3wp_email_reflector_codes`");
			$this->query("RENAME TABLE `".$this->wpdb->base_prefix."_3wp_email_reflector_lists` TO `".$this->wpdb->base_prefix."3wp_email_reflector_lists`");
			$this->query("RENAME TABLE `".$this->wpdb->base_prefix."_3wp_email_reflector_list_settings` TO `".$this->wpdb->base_prefix."3wp_email_reflector_list_settings`");
			$this->query("RENAME TABLE `".$this->wpdb->base_prefix."_3wp_email_reflector_log` TO `".$this->wpdb->base_prefix."3wp_email_reflector_log`");
			$this->query("RENAME TABLE `".$this->wpdb->base_prefix."_3wp_email_reflector_queue` TO `".$this->wpdb->base_prefix."3wp_email_reflector_queue`");
		}
		
		$this->query("CREATE TABLE IF NOT EXISTS `".$this->wpdb->base_prefix."3wp_email_reflector_codes` (
		  `auth_code` varchar(64) CHARACTER SET latin1 NOT NULL COMMENT '64 character hash',
		  `datetime_added` datetime NOT NULL COMMENT 'When this message was added',
		  `message_data` longtext CHARACTER SET latin1 NOT NULL COMMENT 'Serialized message_data',
		  UNIQUE KEY `auth_code` (`auth_code`),
		  KEY `datetime_added` (`datetime_added`)
		) ENGINE=MyISAM DEFAULT CHARSET=utf8 COMMENT='Messages that need authorization or moderation before being ';
		");

		$this->query("CREATE TABLE IF NOT EXISTS `".$this->wpdb->base_prefix."3wp_email_reflector_lists` (
		  `list_id` int(11) NOT NULL AUTO_INCREMENT COMMENT 'ID of list',
		  `datetime_created` datetime NOT NULL COMMENT 'When the list was created',
		  PRIMARY KEY (`list_id`)
		) ENGINE=MyISAM  DEFAULT CHARSET=utf8 COMMENT='Reflector lists' AUTO_INCREMENT=1 ;
		");

		$this->query("CREATE TABLE IF NOT EXISTS `".$this->wpdb->base_prefix."3wp_email_reflector_list_settings` (
		  `list_id` int(11) NOT NULL COMMENT 'List ID',
		  `key` varchar(50) CHARACTER SET latin1 NOT NULL COMMENT 'Hashmap key',
		  `value` longtext CHARACTER SET latin1 NOT NULL COMMENT 'Hashmap value',
		  KEY `key` (`key`),
		  KEY `list_id` (`list_id`)
		) ENGINE=MyISAM DEFAULT CHARSET=utf8 COMMENT='Key/Value hashmap for each list';
		");

		$this->query("CREATE TABLE IF NOT EXISTS `".$this->wpdb->base_prefix."3wp_email_reflector_log` (
		  `id` int(11) NOT NULL AUTO_INCREMENT COMMENT 'Log item ID',
		  `datetime` datetime NOT NULL COMMENT 'Even time',
		  `message` text CHARACTER SET latin1 NOT NULL COMMENT 'Description of the event',
		  PRIMARY KEY (`id`)
		) ENGINE=MyISAM  DEFAULT CHARSET=utf8 COMMENT='Logged events' AUTO_INCREMENT=1 ;
		");

		$this->query("CREATE TABLE IF NOT EXISTS `".$this->wpdb->base_prefix."3wp_email_reflector_queue` (
		  `message_id` int(11) NOT NULL AUTO_INCREMENT COMMENT 'Message ID',
		  `datetime_added` datetime NOT NULL COMMENT 'When this message was queued',
		  `failures` int(11) NOT NULL DEFAULT '0' COMMENT 'How many send failures the message has',
		  `mail_data_short` longtext CHARACTER SET latin1 NOT NULL COMMENT 'The mail_data without the body',
		  `mail_data` longtext CHARACTER SET latin1 NOT NULL COMMENT 'The serialized mail data',
		  PRIMARY KEY (`message_id`)
		) ENGINE=MyISAM  DEFAULT CHARSET=utf8 COMMENT='Mails to send' AUTO_INCREMENT=1 ;
		");
		
		// Version 1.12
		$this->query("CREATE TABLE IF NOT EXISTS `".$this->wpdb->base_prefix."3wp_email_reflector_queue_mail_data` (
		  `mail_data_id` int(11) NOT NULL AUTO_INCREMENT COMMENT 'Message ID',
		  `mail_data_short` longtext CHARACTER SET latin1 NOT NULL COMMENT 'The mail_data without the body',
		  `mail_data` longtext CHARACTER SET latin1 NOT NULL COMMENT 'The serialized mail data',
		  PRIMARY KEY (`mail_data_id`)
		) ENGINE=MyISAM  DEFAULT CHARSET=utf8 COMMENT='Mails to send' AUTO_INCREMENT=1 ;
		");
		
		$database_version = $this->get_site_option( 'database_version' );
		if ( $database_version < 18 )
		{
			// Delete the microtime column
			$fields = $this->sql_describe( '3wp_email_reflector_log' );
			if ( isset( $fields['microtime'] ) )
				$this->query("ALTER TABLE `".$this->wpdb->base_prefix."3wp_email_reflector_log` DROP `microtime`");
			if ( isset( $fields['level'] ) )
				$this->query("ALTER TABLE `".$this->wpdb->base_prefix."3wp_email_reflector_log` DROP `level`");
			
			if ( ! isset( $fields['type'] ) )
				$this->query("ALTER TABLE `".$this->wpdb->base_prefix."3wp_email_reflector_log` ADD `type` VARCHAR( 32 ) NOT NULL COMMENT 'Type of message' AFTER `datetime`");
				 
			$this->query("ALTER TABLE `".$this->wpdb->base_prefix."3wp_email_reflector_log` DROP INDEX `datetime`");
			$this->update_site_option( 'database_version', 18 );
		}
		
		$database_version = $this->get_site_option( 'database_version' );
		if ( $database_version < 19 )
		{
			$this->query("ALTER TABLE `".$this->wpdb->base_prefix."3wp_email_reflector_codes` CHANGE `auth_code` `auth_code` VARCHAR( 32 ) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL COMMENT '32 character hash'");
			$this->update_site_option( 'database_version', 19 );
		}
			
		if ( $database_version < 20 )		// version 1.12 
		{
			$this->query("ALTER TABLE `".$this->wpdb->base_prefix."3wp_email_reflector_queue` DROP `mail_data_short`");
			$this->query("ALTER TABLE `".$this->wpdb->base_prefix."3wp_email_reflector_queue` DROP `mail_data`");
			$this->query("ALTER TABLE `".$this->wpdb->base_prefix."3wp_email_reflector_queue` ADD `mail_data_id` INT NOT NULL COMMENT 'ID of mail data this queue item belongs to' AFTER `message_id` , ADD INDEX ( `mail_data_id` )");
			$this->query("ALTER TABLE `".$this->wpdb->base_prefix."3wp_email_reflector_queue` ADD `to` VARCHAR( 1024 ) NOT NULL COMMENT 'Recipient address'");
			$this->update_site_option( 'database_version', 20 );
		}

		if ( $database_version < 21 )		// version 1.13 
		{
			$this->query("ALTER TABLE `".$this->wpdb->base_prefix."3wp_email_reflector_queue` ADD INDEX ( `failures` )");
			$this->query("ALTER TABLE `".$this->wpdb->base_prefix."3wp_email_reflector_queue` ADD `priority` INT NOT NULL DEFAULT '100' COMMENT 'Priority 100 is normal' AFTER `datetime_added` , ADD INDEX ( `priority` )");  
			$this->update_site_option( 'database_version', 21 );
		}
			
		if ( $database_version < 22 )		// version 1.14 
		{
			// This version moved logging support over to threewp activity monitor.
			$this->query("DROP TABLE `".$this->wpdb->base_prefix."3wp_email_reflector_log`");
			$this->delete_site_option( 'log_types' );
			$this->update_site_option( 'database_version', 22 );
		}
			
		wp_schedule_event(time() + 60, 'hourly', 'threewp_email_reflector_cron');
		$this->schedule_collection();
	}
	
	public function deactivate()
	{
		parent::deactivate();
		wp_clear_scheduled_hook('threewp_email_reflector_cron');
	}

	public function uninstall()
	{
		if ( ! $this->is_admin )
			wp_die('Security.');
		
		parent::uninstall();
		$this->query("DROP TABLE `".$this->wpdb->base_prefix."3wp_email_reflector_codes`");
		$this->query("DROP TABLE `".$this->wpdb->base_prefix."3wp_email_reflector_lists`");
		$this->query("DROP TABLE `".$this->wpdb->base_prefix."3wp_email_reflector_list_settings`");
		$this->query("DROP TABLE `".$this->wpdb->base_prefix."3wp_email_reflector_log`");
		$this->query("DROP TABLE `".$this->wpdb->base_prefix."3wp_email_reflector_queue`");
		$this->query("DROP TABLE `".$this->wpdb->base_prefix."3wp_email_reflector_queue_mail_data`");
	}
	
	public function admin_menu()
	{
		if ( ! $this->role_at_least( $this->get_site_option('security_role_to_use') ) )
			return;
		
		$this->load_language();
		$this->access_types = apply_filters( 'threewp_email_reflector_get_access_types', array() );

		// Assemble a list of access types that enable editing.
		$this->access_for_edit = array();
		foreach( $this->access_types as $access_type => $access_type_data )
			if ( isset( $access_type_data[ 'editable' ] ) )
				$this->access_for_edit[] = $access_type_data[ 'name' ];
		
		$this->is_admin = $this->role_at_least('administrator');
		add_menu_page('Email Reflector', 'Email Reflector', 'read', 'threewp_email_reflector', array(&$this, 'admin' ), $this->paths['url'] . '/images/icon-threewp_email_reflector.png');
		add_filter('user_row_actions', array( &$this, 'user_row_actions' ), 10, 2 );
	}
	
	// --------------------------------------------------------------------------------------------
	// ----------------------------------------- Admin
	// --------------------------------------------------------------------------------------------

	public function admin()
	{
		$this->load_language();
		
		$tab_data = array(
			'tabs'		=>	array(),
			'functions' =>	array(),
		);
		
		$tab_data = apply_filters( 'threewp_email_reflector_admin_get_tab_data', $tab_data );
		
		$this->tabs($tab_data);
	}
	
	public function admin_overview()
	{
		if ( isset( $_POST['action_submit'] ) && isset( $_POST['lists'] ) )
		{
			if ( $_POST['action'] == 'activate' )
			{
				foreach( $_POST['lists'] as $list_id => $ignore )
				{
					$list = $this->sql_list_get( $list_id );
					if ( $list !== false )
					{
						$this->threewp_email_reflector_update_list_setting( $list_id, 'enabled', true );
					}
				}
			}	// activate
			if ( $_POST['action'] == 'deactivate' )
			{
				foreach( $_POST['lists'] as $list_id => $ignore )
				{
					$list = $this->sql_list_get( $list_id );
					if ( $list !== false )
					{
						$this->threewp_email_reflector_update_list_setting( $list_id, 'enabled', false );
					}
				}
			}	// deactivate
			if ( $_POST['action'] == 'delete' )
			{
				foreach( $_POST['lists'] as $list_id => $ignore )
				{
					$list = $this->sql_list_get( $list_id );
					if ( $list !== false )
					{
						$this->sql_list_delete( $list_id );
						$this->message( $this->_( 'List <em>%s</em> deleted.', $list_id ) );
					}
				}
			}	// delete
		}
		
		if ( isset($_POST['create']) && $this->is_admin )
		{
			$list_id = $this->sql_list_add();

			$edit_link = add_query_arg( array(
				'tab' => 'edit',
				'id' => $list_id,
			) );
			
			$this->message( $this->_( 'List created! <a href="%s">Edit the newly-created list</a>.', $edit_link ) );
		}

		if ( isset($_POST['collect_now']) && $this->is_admin )
		{
			$cron_options = new ThreeWP_Email_Reflector_Cron_Options();
			$cron_options->force = true;
			$cron_options->verbose = true;
			$this->cron( $cron_options );
			$this->message( $this->_( 'Collection complete.') );
		}
		
		$form = $this->form();
		$lists = $this->threewp_email_reflector_get_lists_with_settings();

		$user = wp_get_current_user();
		$security_all_lists_visible = $this->get_site_option( 'security_all_lists_visible' );
		
		$tBody = '';
		foreach( $lists as $list )
		{
			$info = array();				// Info array to assemble from the list.
			$list_id = $list['list_id'];	// Convenience.
			$settings = $list['settings'];	// Convenience.
			
			if ( ( ! $security_all_lists_visible === true ) && ! $this->has_access($user->ID, 'see_in_overview', $settings) )
				continue;
			
			$writers = array_filter( explode("\n", $this->all_writers( $settings ) ) );
			$readers = array_filter( explode("\n", $settings->readers ) );
			$moderators = array_filter( explode("\n", $settings->moderators ) );

			if ( $settings->enabled != true )
				$info[] = $this->_( 'List is disabled.' );
			
			if ( $settings->stats_last_collected == '' )
				$info[] = $this->_( 'Never collected.' );
			else
				$info[] = $this->_( 'Collected: %s', $this->ago( $settings->stats_last_collected ) );
			
			$readers_writers_moderators = array();
			
			$readers_writers_moderators[] = $this->_( '%s readers', count($readers) );
			
			if ( count($writers) > 0 )
				$readers_writers_moderators[] = $this->_( '%s writers', count($writers) );
			 
			if ( count($moderators) > 0 )
				$readers_writers_moderators[] = $this->_( '%s moderators', count($moderators) );
			
			$info[] = implode( ' | ', $readers_writers_moderators);

			$info = apply_filters( 'threewp_email_reflector_admin_overview_get_list_info', $info, $settings ); 
			
			$actions = array();
			
			if ( $this->has_access($user->ID, $this->access_for_edit, $settings) )
			{
				$actions = array();

				$url_edit = add_query_arg(array(
					'tab' => 'edit',
					'id' => $list_id,
				));
				$actions[] = '<a href="' . $url_edit . '">'.$this->_( 'Edit' ).'</a>';
				
				if ( $this->is_admin )
				{
					$url_access = add_query_arg(array(
						'tab' => 'access',
						'id' => $list_id,
					));
					$actions[] = '<a href="' . $url_access . '">'.$this->_( 'Access' ).'</a>';
				}

				if ( $this->has_access($user->ID, 'collect', $settings) || $this->is_admin )
				{
					$url_collect = add_query_arg(array(
						'tab' => 'collect',
						'id' => $list_id,
					));
					$actions[] = '<a href="' . $url_collect . '">'.$this->_( 'Collect now' ).'</a>';
				}
				
				if ( $this->is_admin )
				{
					$url_delete = add_query_arg(array(
						'tab' => 'delete',
						'id' => $list_id,
						'_wpnonce' => wp_create_nonce('delete_list_id_' . $list_id),
					));
					$actions[] = '<span class="trash"><a href="' . $url_delete . '">'.$this->_( 'Delete' ).'</a></span>';
				}
			}
			
			foreach( $this->access_types as $access_type )
			{
				if ( !isset( $access_type[ 'action' ] ) )
					continue;
				if ( ! $this->has_access($user->ID, $access_type[ 'name' ], $settings) )
					continue;
				$url_action = add_query_arg(array(
					'tab' => $access_type[ 'name' ],
					'id' => $list_id,
				));
				$actions[] = '<a href="' . $url_action . '">' . $access_type[ 'label' ] . '</a>';
			}

			$actions = implode('<span class="action_sep"> | </span>', $actions);
			
			$cb = '';
			if ( $this->is_admin )
			{
				$input_select_list = array(
					'type' => 'checkbox',
					'checked' => false,
					'label' => $settings->list_name,
					'name' => $list['list_id'],
					'nameprefix' => '[lists]',
				);
				
				$cb = '<th scope="row" class="check-column">' . $form->make_input( $input_select_list ) . ' <span class="screen-reader-text">' . $form->make_label( $input_select_list ) . '</span></th>';
			}
 
			$tBody .= '<tr>
				' . $cb . '
				<td>
					<div>'. $settings->list_name .'<br />'.$settings->reply_to.'</div>
					<div class="row-actions">' . $actions . '</div>
				</td>
				<td>'. implode('<br />', $info) .'</td>
			</tr>';
		}
		
		$admin = '';
		$select = '';
		if ( $this->is_admin )
		{
			$input_actions = array(
				'type' => 'select',
				'name' => 'action',
				'label' => $this->_('With the selected rows'),
				'options' => array(
					''				=> $this->_('Do nothing'),
					'activate'		=> $this->_('Activate'),
					'deactivate'	=> $this->_('Deactivate'),
					'delete'		=> $this->_('Delete'),
				),
			);
			
			$input_action_submit = array(
				'type' => 'submit',
				'name' => 'action_submit',
				'value' => $this->_('Apply'),
				'css_class' => 'button-secondary',
			);
			
			$admin = '
				'.$form->start().'
				<p>
					' . $form->make_label( $input_actions ) . '
					' . $form->make_input( $input_actions ) . '
					' . $form->make_input( $input_action_submit ) . '
				</p>
			';

			$selected = array(
				'type' => 'checkbox',
				'name' => 'check',
			);
			$select = '<th class="check-column">' . $form->make_input( $selected ) . '<span class="screen-reader-text">' . $this->_('Selected') . '</span></th>';
		}
		echo '
			' . $admin . '
			<table class="widefat">
			<thead>
				<tr>
					' . $select . '
					<th>'.$this->_( 'Name').'</th>
					<th>'.$this->_( 'Info').'</th>
				</tr>
			</thead>
			<tbody>
				'.$tBody.'
			</tbody>
		</table>';
		
		if ($this->is_admin)
		{
			$inputs = array(
				'collect_now' => array(
					'name' => 'collect_now',
					'type' => 'submit',
					'value' => $this->_( 'Collect all active mailing lists' ),
					'css_class' => 'button-secondary',
				),
				'create' => array(
					'name' => 'create',
					'type' => 'submit',
					'value' => $this->_( 'Create a new mailing list' ),
					'css_class' => 'button-primary',
				),
			);
	
			echo '
				
				<p>
					'.$form->make_input( $inputs['collect_now'] ).'
				</p>
	
				<p>
					'.$form->make_input( $inputs['create'] ).'
				</p>
	
				'.$form->stop().'
			';
		}
	}
	
	protected function admin_edit()
	{
		$list_id = $_GET['id'];
		$data = $this->sql_list_get( $list_id );
		
		if ($data === false)
			wp_die("List $id does not exist!");
		
		$user = wp_get_current_user();
		
		$form = $this->form();
		$inputs = apply_filters( 'threewp_email_reflector_admin_edit_get_inputs', array() );
		
		if ( isset($_POST['save']) )
		{
			$settings = $this->threewp_email_reflector_get_list_settings( $list_id );
		
			$keys_to_validate = array();
			foreach( array_keys($inputs) as $key )
				if ( isset( $inputs[$key]['access_type'] ) )
					if ( $this->has_access( $user->ID, $inputs[$key]['access_type'], $settings ) )
						$keys_to_validate[] = $key;
			
			$result = $form->validate_post($inputs, $keys_to_validate, $_POST);
			
			if ( $result === true )
			{
				$_POST = apply_filters( 'threewp_email_reflector_admin_edit_save', $_POST );

				// Fix the email lines in case the user is importing with commas.
				foreach( array( 'accept_to', 'moderators', 'writers_auth', 'writers_nomod', 'writers', 'readers' ) as $type )
					if ( isset($_POST[$type]) )
						$_POST[$type] = $this->clean_email_textarea( $_POST[$type] );
				
				$_POST[ 'priority_modifier' ] = intval ( $_POST[ 'priority_modifier' ] );
				
				foreach( $inputs as $input )
					if ( isset($input['is_setting']) && $this->has_access( $user->ID, $input['access_type'], $settings ) )
					{
						if ( $input['type'] == 'checkbox' && !isset($_POST[ $input['name'] ]) )
							$_POST[ $input['name'] ] = false;
						$this->threewp_email_reflector_update_list_setting( $list_id, $input['name'], $_POST[ $input['name'] ] );
					}
				$this->message( $this->_('The settings have been saved!') );
			}
			else
				$this->error( implode('<br />', $result) );
		}
		
		$settings = $this->threewp_email_reflector_get_list_settings( $list_id );
		foreach($inputs as $index => $input)
		{
			if ( isset($input['is_emails']) )
			{
				$emails = array_filter( explode("\n", $settings->$index) );
				foreach($emails as $email)
					if ( ! is_email( trim($email) ) )
						$this->error('The email address: '.$email.' in ' . $input['label'] .' is invalid!');
			}
			if ( isset($input['is_setting']) )
				if ( $inputs[$index] == 'checkbox' )
					$inputs[$index]['checked'] = @$settings->$index;
				else
					$inputs[$index]['value'] = @$settings->$index;

			$form->use_post_value( $inputs[$index], $_POST );
		}
	
		$rv = $form->start();

		$rv .= $form->make_input( $inputs['save'] );
		
		$setting_types = apply_filters( 'threewp_email_reflector_admin_edit_get_setting_types', array() );
		
		foreach( $setting_types as $type => $type_data )
		{
			if ( ! $this->has_access( $user->ID, $type, $settings ) )
				continue;

			$inputs_to_display = array();
			foreach( $inputs as $input )
			{
				if ( !isset( $input[ 'access_type'] ) )
					continue;
				if ( ! is_array( $input[ 'access_type' ] ) )
					$input[ 'access_type' ] = array( $input[ 'access_type' ] );
				if ( ! in_array( $type, $input[ 'access_type' ] ) )
					continue;
				$inputs_to_display[] = $input;
			}

			$rv .= '<h3>'. $type_data[ 'heading' ] .'</h3>';
			$rv .= $this->display_form_table( $inputs_to_display );
		}

		$rv .= '<p>' . $form->make_input( $inputs['save'] ) . '</p>';
		$rv .= $form->stop();
		
		echo $rv;
	}
	
	protected function admin_delete()
	{
		$list_id = $_GET['id'];
		$data = $this->sql_list_get( $list_id );
		
		if ($data === false)
			wp_die("List $id does not exist!");
		
		$nonce = 'delete_list_id_' . $list_id;
		if (! wp_verify_nonce( $_GET['_wpnonce'], $nonce) )
			wp_die('Security check');
		
		if ( isset( $_POST['input_delete'] ) )
		{
			$this->delete_list( $list_id );
			$this->message( 'The list has been deleted! You may now return to the overview.');
			return;
		}
			
		$settings = $this->threewp_email_reflector_get_list_settings( $list_id );
		$form = $this->form();
		$input_delete = array(
			'name' => 'input_delete',
			'type' => 'submit',
			'value' => 'Remove this mailing list',
			'css_class' => 'button-primary',
		);
		
		echo '
			<p>
				You are about to delete the mailing list: ' . $settings->list_name . '.
			</p>
			<p>
				Are you sure?
			</p>
			'. $form->start() .'
			'. $form->make_input($input_delete) .'
			'. $form->stop() .'
		';
	}
	
	protected function admin_collect()
	{
		$list_id = $_GET['id'];
		$data = $this->sql_list_get( $list_id );
		
		$user = wp_get_current_user();
		$settings = $this->threewp_email_reflector_get_list_settings( $list_id );
		if ( ! $this->has_access( $user->ID, 'collect', $settings ) )
			wp_die( 'No access.' );

		if ($data === false)
			wp_die("List $id does not exist!");
		
		$this->message( $this->_( 'Connecting to <em>%s</em> with username <em>%s</em>.', $settings->server_hostname, $settings->server_username ) );
		
		$stream = $this->imap_connect( $settings ); 

		if ($stream === false)
		{
			$this->error( $this->_( 'IMAP stream could not be opened!' ) );
			return;
		}
		
		$options = new ThreeWP_Email_Reflector_Process_Options();
		$options->list_id = $list_id;
		$options->stream = $stream;
		$options->settings = $settings;
		$options->verbose = true;
		$this->process( $options );
		$this->threewp_email_reflector_update_list_setting( $list_id, 'stats_last_collected', $this->now() );

		$this->message( $this->_('Done.') );
	}
	
	protected function admin_mass_edit()
	{
		$form = $this->form();
		$inputs = array(
			'lists' => array(
				'name' => 'lists',
				'type' => 'select',
				'label' => $this->_( 'Lists to modify' ),
				'description' => 'Multiple lists can be selected with CTRL.',
				'multiple' => true,
				'options' => array(),
				'css_style' => 'min-height: 20em;',
			),
			'update_cron_minutes' => array(
				'name' => 'update_cron_minutes',
				'type' => 'text',
				'label' => $this->_( 'Minutes between collections' ),
				'validation' => array( 'empty' => true ),
			),
			'update' => array(
				'type' => 'submit',
				'name' => 'update',
				'value' => $this->_('Update lists'),
				'css_class' => 'button-primary',
			),
		);
		
		if ( isset($_POST['update']) && count ( $_POST['lists'] ) > 0 )
		{
			$lists = $_POST['lists'];
			foreach( $lists as $list_id )
			{
				foreach( $inputs as $key => $input )
				{
					if ( strpos( $key, 'update_' ) === false )
						continue;
					$new_value = $_POST[ $key ];
					if ( $new_value == '' )
						continue;
					$key = str_replace( 'update_', '', $key );
					$this->threewp_email_reflector_update_list_setting( $list_id, $key, $new_value );
				}
			}
			
			$this->message( $this->_('The lists have been mass edited.') );
		}
		
		foreach ($inputs as $key => $input )
			$form->use_post_value( $inputs[$key], $_POST );
		
		$lists = $this->threewp_email_reflector_get_lists_with_settings();
		
		foreach( $lists as $list )
			$inputs['lists']['options'][] = array( 'value' => $list['list_id'], 'text' => $list['list_name'] );
		
		echo $form->start() . '
			<h3>Lists to mass edit</h3>
			
			' . $this->display_form_table( array( $inputs['lists'] ) ) . '
			
			<h3>New settings</h3>
			
			<p>
				' . $this->_( 'To leave a setting unchanged, leave it empty.' ). '
			</p>
			
			' . $this->display_form_table( array( $inputs['update_cron_minutes'] ) ) . '
			
			';
			
		echo '
			<p>
				' . $form->make_input($inputs['update']) . '
			</p>
			' . $form->stop() . '
		';
	}
	
	protected function admin_queue()
	{
		if ( isset( $_POST['action_submit'] ) && isset( $_POST['message_ids'] ) )
		{
			if ( $_POST['action'] == 'delete' )
			{
				foreach( $_POST['message_ids'] as $message_id => $ignore )
				{
					$this->sql_unqueue( $message_id );
					$this->message( $this->_( 'Message <em>%s</em> has been removed from the queue.', $message_id ) );
				}
			}	// delete
			if ( $_POST['action'] == 'send' )
			{
				foreach( $_POST['message_ids'] as $message_id => $ignore )
				{
					if ( $this->send_message( $message_id ) )
						$this->message( $this->_( 'Message <em>%s</em> has been sent.', $message_id ) );
					else
						$this->error( $this->_( 'Message <em>%s</em> could not be sent.', $message_id ) );
				}
			}	// send
		}
		
		if ( isset($_POST['process_queue']) )
		{
			$this->process_queue();
			$this->message( $this->_( 'Messages have been sent.') );
		}
		
		$items_per_page = $this->get_site_option( 'items_per_page' ); 
		$count = $this->threewp_email_reflector_get_queue_size();
		$max_pages = ceil($count / $items_per_page);
		
		$current_page = @ $_GET['paged'];
		$current_page = $this->minmax( intval( $current_page ), 1, $max_pages);
		
		$queue = $this->sql_queue_list(array(
			'limit' => $items_per_page,
			'page' => $current_page,
		));
		
        $page_links = paginate_links( array(
                'base' => add_query_arg( 'paged', '%#%' ),
                'format' => '',
                'prev_text' => $this->_('&laquo;'),
                'next_text' => $this->_('&raquo;'),
                'current' => $current_page,
                'total' => $max_pages,
        ));

        if ($page_links)
        	$page_links = '<div class="tablenav"><div class="tablenav-pages">' . $page_links . '</div></div>';
        else
        	$page_links = '';
		
		$form = $this->form();
		$inputs = array(
			'process_queue' => array(
				'name' => 'process_queue',
				'type' => 'submit',
				'value' => $this->_( 'Send messages in the queue' ),
				'css_class' => 'button-primary',
			),
		);
		
		$tBody = '';
		foreach( $queue as $queued )
		{
			$info = array();				// Info array to assemble from the list.
			$mail_data = $this->sql_decode( $queued['mail_data_short'] );
			
			$from = preg_replace( '/.*From: /s', '\1', $mail_data['headers'] );
			$from = preg_replace( '/\n.*/s', '', $from );
			$from = trim( $from );
			$from = htmlspecialchars( $from );
			
			$info[] =  $this->_( 'List: %s', reset($mail_data['reply_to']) );
			$info[] =  $this->_( 'From: %s', $from );
			$info[] =  $this->_( 'To: %s', $queued['to'] );
			$info[] =  $this->_( 'Subject: %s', htmlspecialchars( imap_utf8($mail_data['subject']) ) );
			
			if ( $queued['failures'] > 0 )
				$info[] = $this->_( 'Failures to send: %s', $queued['failures'] );
			
			$input_select_message = array(
					'type' => 'checkbox',
					'checked' => false,
					'label' => $queued['message_id'],
					'name' => $queued['message_id'],
					'nameprefix' => '[message_ids]',
				);
			
			$tBody .= '<tr>
				<th scope="row" class="check-column">' . $form->make_input( $input_select_message ) . ' <span class="screen-reader-text">' . $form->make_label( $input_select_message ) . '</span></th>
				<td>'. $queued['message_id'] .'</td>
				<td>'. $queued['datetime_added'] .'</td>
				<td>'. $queued['priority'] .'</td>
				<td>'. implode('<br />', $info) .'</td>
			</tr>';
		}
		
		echo $page_links;
		
		$input_actions = array(
			'type' => 'select',
			'name' => 'action',
			'label' => $this->_('With the selected rows'),
			'options' => array(
				''				=> $this->_('Do nothing'),
				'delete'		=> $this->_('Delete'),
				'send'			=> $this->_('Send'),
			),
		);
		
		$input_action_submit = array(
			'type' => 'submit',
			'name' => 'action_submit',
			'value' => $this->_('Apply'),
			'css_class' => 'button-secondary',
		);
		
		echo '
			'.$form->start().'
			<p>
				' . $form->make_label( $input_actions ) . '
				' . $form->make_input( $input_actions ) . '
				' . $form->make_input( $input_action_submit ) . '
			</p>
		';

		$selected = array(
			'type' => 'checkbox',
			'name' => 'check',
		);
		
		echo '<table class="widefat">
			<thead>
				<tr>
					<th class="check-column">' . $form->make_input( $selected ) . '<span class="screen-reader-text">' . $this->_('Selected') . '</span></th>
					<th>' . $this->_( 'ID' ) . '</th>
					<th>' . $this->_( 'Queued' ) . '</th>
					<th>' . $this->_( 'Priority' ) . '</th>
					<th>' . $this->_( 'Info' ) . '</th>
				</tr>
			</thead>
			<tbody>
				'.$tBody.'
			</tbody>
		</table>
		
		' . $page_links . '
		
		' . $form->make_input( $inputs['process_queue'] ) . '
		' . $form->stop() . '
		
		';
	}
	
	protected function admin_codes()
	{
		if ( isset( $_POST['action_submit'] ) && isset( $_POST['auth_codes'] ) )
		{
			if ( $_POST['action'] == 'accept' )
			{
				foreach( $_POST['auth_codes'] as $auth_code => $ignore )
				{
					$this->accept_code( $auth_code );
					$this->sql_delete_code( $auth_code );
					$this->message( $this->_( 'The code <em>%s</em> has been accepted.', $auth_code ) );
				}
			}	// accept
			if ( $_POST['action'] == 'delete' )
			{
				foreach( $_POST['auth_codes'] as $auth_code => $ignore )
				{
					$this->sql_delete_code( $auth_code );
					$this->message( $this->_( 'The code <em>%s</em> has been deleted.', $auth_code ) );
				}
			}	// delete
		}
		
		$items_per_page = $this->get_site_option( 'items_per_page' ); 
		$count = $this->sql_count_codes();
		$max_pages = ceil($count / $items_per_page);
		
		$current_page = @ $_GET['paged'];
		$current_page = $this->minmax( intval( $current_page ), 1, $max_pages);
		
		$codes = $this->sql_list_codes(array(
			'limit' => $items_per_page,
			'page' => $current_page,
		));
		
        $page_links = paginate_links( array(
                'base' => add_query_arg( 'paged', '%#%' ),
                'format' => '',
                'prev_text' => $this->_('&laquo;'),
                'next_text' => $this->_('&raquo;'),
                'current' => $current_page,
                'total' => $max_pages,
        ));

        if ($page_links)
        	$page_links = '<div class="tablenav"><div class="tablenav-pages">' . $page_links . '</div></div>';
        else
        	$page_links = '';

		$form = $this->form();

		$tBody = '';
		foreach( $codes as $code )
		{
			$data = $this->sql_decode( $code['message_data'] );
			$headerinfo = $data->headerinfo;		// Convenience
			$from = $this->assemble_address( $headerinfo->from );
			$list_name = $data->settings->list_name;

			$info = array();				// Info array to assemble from the list.
			
			$message_data = $this->sql_decode( $code['message_data'] );
			switch( $message_data->code_type )
			{
				case 'mod':
					$code_type = $this->_( 'Moderation' );
					break;
				default:
					$code_type = $this->_( 'Authorization' );
					break;
			}
			
			$info[] = $this->_( 'List: <em>%s</em>', $list_name );

			$info[] = $this->_( 'From: <em>%s</em>', $from );

			$input_select_message = array(
					'type' => 'checkbox',
					'checked' => false,
					'label' => $code['auth_code'],
					'name' => $code['auth_code'],
					'nameprefix' => '[auth_codes]',
				);
			
			$tBody .= '<tr>
				<th scope="row" class="check-column">' . $form->make_input( $input_select_message ) . ' <span class="screen-reader-text">' . $form->make_label( $input_select_message ) . '</span></th>
				<td>'. $code['auth_code'] .'</td>
				<td>'. $code_type .'</td>
				<td>'. $code['datetime_added'] .'</td>
				<td>'. implode('<br />', $info) .'</td>
			</tr>';
		}
		
		echo $page_links;
		
		$input_actions = array(
			'type' => 'select',
			'name' => 'action',
			'label' => $this->_('With the selected rows'),
			'options' => array(
				''				=> $this->_('Do nothing'),
				'accept'		=> $this->_('Accept'),
				'delete'		=> $this->_('Delete'),
			),
		);
		
		$input_action_submit = array(
			'type' => 'submit',
			'name' => 'action_submit',
			'value' => $this->_('Apply'),
			'css_class' => 'button-secondary',
		);
		
		$selected = array(
			'type' => 'checkbox',
			'name' => 'check',
		);
		
		echo '
			'.$form->start().'
			<p>
				' . $form->make_label( $input_actions ) . '
				' . $form->make_input( $input_actions ) . '
				' . $form->make_input( $input_action_submit ) . '
			</p>
			<table class="widefat">
			<thead>
				<tr>
					<th class="check-column">' . $form->make_input( $selected ) . '<span class="screen-reader-text">' . $this->_('Selected') . '</span></th>
					<th>' . $this->_( 'Code' ) . '</th>
					<th>' . $this->_( 'Code type' ) . '</th>
					<th>' . $this->_( 'Date' ) . '</th>
					<th>' . $this->_( 'Info' ) . '</th>
				</tr>
			</thead>
			<tbody>
				'.$tBody.'
			</tbody>
		</table>
		
		' . $page_links . '

		';
	}
	
	protected function admin_access()
	{
		$list_id = $_GET['id'];

		// Contains an array of [user_id] => array( [access_type] => true, )
		$user_access_settings = array();
		
		$tHead = '';
		// Build the tHead and check the post at the same time.
		foreach( $this->access_types as $access_type )
		{
			$access_type = array_merge( array(
				'title' => '',
			), $access_type );
			if ( isset( $_POST[ $access_type['name'] ] ) )
			{
				foreach( $_POST[ $access_type['name'] ] as $user_id => $ignore )
				{
					if ( ! isset( $user_access_settings[ $user_id ] ) )
						$user_access_settings[ $user_id ] = array(); 
					$user_access_settings[ $user_id ][ $access_type['name'] ] = true;
				}
			} 
			$tHead .= '<th title="'.$access_type['title'].'">'.$access_type['label'].'</th>';
		}
		
		// Save the new user settings if necessary.
		if ( count($_POST) > 0 )
		{
			$this->sql_access_settings_save( $list_id, $user_access_settings );
			$this->message( $this->_( 'Access settings for this list have been updated.') );
		}

		$users = get_users();
		$tBody = '';
		$form = $this->form();
		$settings = $this->threewp_email_reflector_get_list_settings( $list_id );
		foreach( $users as $user )
		{
			if ( user_can( $user->ID, 'manage_options' ) )
				continue;

			$user_url = add_query_arg( array(
				'page' => 'threewp_email_reflector',
				'tab' => 'user access',
				'id' => $user->ID,
			) , 'admin.php' );
	
			$tBody .= '<tr>';
			$tBody .= '<td><a title="' . $this->_("See all of the user's lists") . '" href="'.$user_url.'">'.$user->user_login.' - '.$user->display_name.'</a></td>';
			foreach( $this->access_types as $access_type )
			{
				$input = array(
					'type' => 'checkbox',
					'name' => $user->ID,
					'nameprefix' => '['.$access_type['name'].']',
					'label' => '<span class="screen-reader-text">'. $this->_( 'Yes') .'</span>',
					'checked' => $this->has_access( $user->ID, $access_type['name'], $settings ),
				);
				$tBody .= '<td>'.$form->make_input($input).' '.$form->make_label($input).'</td>';
			}
			$tBody .= '</tr>';
		}
		
		$input_save =array(
				'name' => 'save',
				'type' => 'submit',
				'value' => $this->_( 'Save changes' ),
				'css_class' => 'button-primary',
		);
		
		echo '
			<p>
				'. $this->_('Allow specific users access to the various parts of mailing list %s.', '<em>' . $settings->list_name . '</em>' ).'
			</p>
		'.$form->start().'
		<table class="widefat">
			<thead>
				<tr>
					<th>User</th>
					'.$tHead.'
				</tr>
			</thead>
			<tbody>
				'.$tBody.'
			</tbody>
		</table>
		
		<p>
			'.$form->make_input( $input_save ) .'
		</p>
		'.$form->stop().'
		';
	}
	
	protected function admin_user_access()
	{
		$user_id = $_GET['id'];
		$form = $this->form();

		$lists = $this->threewp_email_reflector_get_lists_with_settings();
		
		$tHead = '';
		$user_access_settings = array();
		// Build the tHead and check the post at the same time.
		foreach( $this->access_types as $access_type )
		{
			$access_type = array_merge( array(
				'title' => '',
			), $access_type );
			if ( isset( $_POST[ $access_type['name'] ] ) )
			{
				foreach( $_POST[ $access_type['name'] ] as $list_id => $ignore )
				{
					if ( ! isset( $user_access_settings[ $list_id ] ) )
						$user_access_settings[ $list_id ] = array(); 
					$user_access_settings[ $list_id ][ $access_type['name'] ] = true;
				}
			}
			$tHead .= '<th title="'.$access_type['title'].'">'.$access_type['label'].'</th>';
		}
		
		// Save the new access settings if necessary.
		if ( count($_POST) > 0 )
		{
			$this->message( $this->_( 'Access settings for this user have been updated.') );
			
			// Fill up the list with all the missing lists. This will _remove_ access to those lists that aren't checked at all.
			foreach( $lists as $list )
			{
				$list_id = $list['list_id'];
				if ( ! isset( $user_access_settings[$list_id] ) )
					$user_access_settings[$list_id] = array();
			}
			$this->sql_user_access_settings_save( $user_id, $user_access_settings );
		}

		$tBody = '';
		foreach( $lists as $list )
		{
			$list_id = $list['list_id'];
			$settings = $this->threewp_email_reflector_get_list_settings( $list_id );
			
			$list_url = add_query_arg( array(
				'page' => 'threewp_email_reflector',
				'tab' => 'access',
				'id' => $list_id,
			) , 'admin.php' );
	
			$tBody .= '<tr>';
			$tBody .= '<td><a title="' . $this->_("See the access settings for this list") . '" href="'.$list_url.'">'.$settings->list_name.'</a></td>';

			foreach( $this->access_types as $access_type )
			{
				$input = array(
					'type' => 'checkbox',
					'name' => $list_id,
					'nameprefix' => '['.$access_type['name'].']',
					'label' => '<span class="screen-reader-text">'. $this->_( 'Yes') .'</span>',
					'checked' => $this->has_access( $user_id, $access_type['name'], $settings ),
				);
				$tBody .= '<td>'.$form->make_input($input).' '.$form->make_label($input).'</td>';
			}

			$tBody .= '</tr>';
		}

		$input_save =array(
				'name' => 'save',
				'type' => 'submit',
				'value' => $this->_( 'Save changes' ),
				'css_class' => 'button-primary',
		);
		
		echo '
			<p>
				'. $this->_('Allow this user access to the various parts of the mailing lists.') .'
			</p>
		'.$form->start().'
		<table class="widefat">
			<thead>
				<tr>
					<th>' . $this->_('Mailing list') . ' </th>
					'.$tHead.'
				</tr>
			</thead>
			<tbody>
				'.$tBody.'
			</tbody>
		</table>
		
		<p>
			'.$form->make_input( $input_save ) .'
		</p>
		'.$form->stop().'
		';
	}
	
	protected function admin_view_readers_and_writers()
	{
		$list_id = $_GET['id'];
		$data = $this->sql_list_get( $list_id );
		
		if ($data === false)
			wp_die("List $id does not exist!");
		
		$form = $this->form();
		$inputs = array();		// Inputs to display to the user.
		$user = wp_get_current_user();
		$settings = $this->threewp_email_reflector_get_list_settings( $list_id );
		
		$all_inputs = apply_filters( 'threewp_email_reflector_admin_edit_get_inputs', array() );
		foreach( $all_inputs as $input )
		{
			$accesses = $input[ 'access_type' ];
			if ( ! is_array( $accesses ) )
				$accesses = array( $accesses );
			if ( ! in_array( 'view_readers_and_writers', $accesses ) )
				continue;
			$input[ 'readonly' ] = true;
			$name = $input[ 'name' ];
			$input[ 'value' ] = $settings->$name;
			$inputs[] = $input;
		}
		
		$rv .= $this->display_form_table( $inputs );
		
		echo $rv;
	}
	
	public function cron( $options = null )
	{
		if ( $options === null )
			$options = new ThreeWP_Email_Reflector_Cron_Options();
		
		if ( $this->get_site_option('enabled') == false && $options->force !== true )
			return;
		
		$this->load_language();
		
		$this->collect_lists( $options );
		$this->process_queue();
		$this->clean_old();
		$this->schedule_collection();
		if ( $options->verbose )
			$this->message( $this->_('Cron complete.') );
		return;
	}
	
	/**
		Collects all the lists.

		@param		$options		ThreeWP_Email_Reflector_Cron_Options settings.
	**/
	public function collect_lists( $options )
	{
		// Collect them all! Just like pokemon...
		$lists = $this->threewp_email_reflector_get_lists();
		$connections = $this->get_site_option( 'collection_connections' );	// Conv
		$connections_per_server = $this->get_site_option( 'collection_connections_per_server' );	// Conv
		
		$curl_master = array();
		
		while ( count($lists) > 0 )
		{
			$curl_jobs = array();
			$server_connections = array();

			foreach($lists as $list_index => $list)
			{
				$list_id = $list['list_id'];
				$settings = $this->threewp_email_reflector_get_list_settings( $list_id );
				$list_name = $settings->list_name;
				
				// Only enabled lists interest us.
				if ( $settings->enabled != true )
				{
					unset( $lists[ $list_index ] );
					continue;
				}
				
				// Is this list ripe for the picking?
				$list_last_collected = $settings->stats_last_collected;
				if (
					( $this->time() - strtotime($list_last_collected) >= 60 * $settings->cron_minutes )
					|| $options->force === true
				)
				{
					$hostname = $settings->server_hostname;		// Conv.
					$ok_for_curl = false;
					if ( $connections_per_server < 1 )
						$ok_for_curl = true;
					else
					{
						if ( !isset( $server_connections[$hostname] ) )
							$server_connections[$hostname] = 0;
						if ( $server_connections[$hostname] < $connections_per_server )
							$ok_for_curl = true;
						else
							$this->log( 'curl', $this->_( 'Too many connections to <em>%s</em>. Skipping list <em>%s</em>.', $hostname, $list_name ) );
					}
					
					if ( $ok_for_curl )
					{
						// Prepare a curl job for this list.
						$url = get_bloginfo( 'url' );
						$url = add_query_arg( array(
							'threewp_email_reflector' => true,
							'list_id' => $list_id,
							'nonce' => substr( $this->hash( $list_id . NONCE_SALT ), 0, 12),
						), $url );
						$curl = $this->curl_init( $url );
						$curl_jobs[] = $curl;
						$server_connections[$hostname]++;
						unset( $lists[ $list_index ] );
					}
				}
				else
				{
					$this->log( 'collect', $this->_( 'Too early to collect <em>%s</em>.', $settings->list_name ) );
					unset( $lists[ $list_index ] );
					continue;
				}
				
				if ( count( $curl_jobs ) == $connections )
					break;
			}
			
			if ( count($curl_jobs) > 0 )
			{
				$this->log( 'curl', $this->_( 'Starting %s fetching children.', count($curl_jobs) ) );
				$mh = curl_multi_init();
				
				foreach( $curl_jobs as $curl )
					curl_multi_add_handle( $mh, $curl );

				$running = null;

				do
				{
					curl_multi_exec($mh, $running);
					usleep( 25000 );
				}
				while( $running );
				
				foreach( $curl_jobs as $curl )
					curl_multi_remove_handle($mh, $curl);
				curl_multi_close( $mh );
			}
			else
				break; 
		}
	}
	
	public function wp_loaded()
	{
		if ( isset( $_REQUEST['threewp_email_reflector'] ) )
		{
			if ( isset( $_REQUEST['list_id'] ) )
			{
				$list_id = $_REQUEST['list_id'];
				$this_hash = $this->hash( $list_id . NONCE_SALT );
				$this_hash = substr( $this_hash, 0, 12 );
				if ( $_REQUEST['nonce'] != $this_hash )
					wp_die('Security check');

				$this->load_language();
				
				$this->filters( 'threewp_email_reflector_process_list', $list_id );				
				exit;
			}	// if ( isset( $_REQUEST['list_id'] ) )
			
			if ( isset( $_REQUEST['message_id'] ) )
			{
				$message_id = $_REQUEST['message_id'];
				$this_hash = $this->hash( $message_id . NONCE_SALT );
				$this_hash = substr( $this_hash, 0, 12 );
				if ( $_REQUEST['nonce'] != $this_hash )
					wp_die('Security check');
				
				$this->send_message( $message_id );
			}	// if ( isset( $_REQUEST['message_id'] ) )			

			if ( isset( $_REQUEST[ 'event' ] ) )
			{
				$this->load_language();
				$this->filters( 'threewp_email_reflector_handle_event' );
				exit;
			}
			
		}	// if ( isset( $_REQUEST['threewp_email_reflector'] ) )
	}
	
	public function admin_settings()
	{
		$rv = '';
		$form = $this->form();
		
		$inputs = apply_filters( 'threewp_email_reflector_admin_settings_get_inputs', array() );
		
		if ( isset($_POST['save']) )
		{
			$keys_to_validate = array();
			foreach ( $inputs as $key => $input )
				if ( isset($input['save']) )
					$keys_to_validate[$key] = $input;
			
			$result = $form->validate_post($inputs, array_keys($keys_to_validate), $_POST);
			
			if ( $result === true )
			{
				$_POST = apply_filters( 'threewp_email_reflector_admin_settings_save', $_POST );
				
				$_POST[ 'priority_decrease_per' ] = intval( $_POST[ 'priority_decrease_per' ] );
				
				foreach ( $keys_to_validate as $key => $input )
				{
					if ( $input['type'] == 'checkbox' && !isset($_POST[ $key ]) )
						$_POST[ $key ] = false;
					$this->update_site_option( $key, $_POST[ $key ] );
				}
				
				$this->message( 'The settings have been saved!');
				
				// Reload the settings in case the other plugin's have updated the database settings.
				$inputs = apply_filters( 'threewp_email_reflector_admin_settings_get_inputs', array() );
			}
			else
				$this->error( implode('<br />', $result ) );
		}
		
		foreach ( $inputs as $key => $input )
			if ( isset( $input['save'] ) )
				if ( $input['type'] == 'checkbox' )
					$inputs[$key]['checked'] = $this->get_site_option( $key );
				else
					$inputs[$key]['value'] = $this->get_site_option( $key );
		
		$rv .= $form->start();
		
		$setting_types = apply_filters( 'threewp_email_reflector_admin_settings_get_setting_types', array() );
		foreach( $setting_types as $setting_type => $type_data )
		{
			$rv .= '<h3>' . $type_data[ 'heading' ] . '</h3>';
			$inputs_to_display = array();
			foreach( $inputs as $input )
			{
				if ( isset( $input[ 'setting_type' ] ) && ( $input[ 'setting_type' ] == $setting_type ) )
				{
					$form->use_post_value( $input, $_POST );
					$inputs_to_display[] = $input;
				}
			}
			$rv .= $this->display_form_table( $inputs_to_display );
		}
		
		$rv .= '<p>' . $form->make_input( $inputs[ 'save' ] ) . '</p>';

		$rv .= $form->stop();

		echo $rv;
	}
	
	public function admin_statistics()
	{
		$form = $this->form();
		
		$rv = '';

		$lists = $this->threewp_email_reflector_get_lists();

		// List of moderators
		$moderators = array();
		foreach( $lists as $list )
		{
			$settings = $this->threewp_email_reflector_get_list_settings( $list['list_id'] );
			$moderators = array_merge(
				$moderators,
				explode( "\n", $settings->moderators )
			 );
		}
		$moderators = array_filter( $moderators );
		$moderators = array_flip( $moderators );
		$moderators = array_flip( $moderators );
		asort( $moderators );
		
		$rv .= '
			<h3>' . $this->_( 'Moderators' ) . '</h3>
			
			<p>
				' . $this->_( 'The list below shows all the moderators in the lists.' ) . '
			</p>
			
			<textarea cols="50" rows="10" >' . implode( "\n", $moderators ) . '</textarea>
		';
		
		echo $rv;
	}
	
	public function user_row_actions($actions, $user)
	{
		if ( user_can( $user->data->ID, 'manage_options' ) )
			return $actions;
		$url = add_query_arg( array(
			'page' => 'threewp_email_reflector',
			'tab' => 'user access',
			'id' => $user->data->ID,
		) , 'admin.php' );
		$actions['emailreflector'] = '<a href="'.$url.'">'. $this->_('Email Reflector access') .'</a>';
		return $actions;
	}
	

	// --------------------------------------------------------------------------------------------
	// ----------------------------------------- Callbacks
	// --------------------------------------------------------------------------------------------
	
	/**
		@brief		Supply a list with our activities.
	**/
	public function threewp_activity_monitor_list_activities( $activities )
	{
		$log_types = apply_filters( 'threewp_email_reflector_get_log_types', array() );
		foreach( $log_types as $type => $data )
		{
			$activity_name = 'threewp_email_reflector_' . $type;
			$activity = array(
				'name' => $data[ 'label' ],
				'plugin' => 'ThreeWP Email Reflector',
			);
			$activities[ $activity_name ] = $activity;
		}
		return $activities;
	}
	/**
		@brief		Return a list of setting inputs.
		@param		$inputs
						Array to modify or append to.
		@return		An array of inputs, ready to be put into SD_Form.
	**/
	public function threewp_email_reflector_admin_edit_get_inputs( $inputs )
	{
		return array_merge( $inputs, array(
			// edit_general
			'list_name' => array(
				'access_type' => 'edit_general',
				'description' => $this->_( 'A short name / description of this mailing list. You can use the e-mail address of this list as the name if you want.' ),
				'is_setting' => true,
				'label' => $this->_( 'List name' ),
				'name' => 'list_name',
				'size' => 50,
				'type' => 'text',
			),
			'enabled' => array(
				'access_type' => 'edit_general',
				'description' => $this->_( 'Check the mailing list automatically for new mail.' ),
				'is_setting' => true,
				'label' => $this->_( 'Enabled' ),
				'name' => 'enabled',
				'type' => 'checkbox',
			),
			'cron_minutes' => array(
				'access_type' => 'edit_general',
				'description' => $this->_( 'How long to wait between collections.' ),
				'is_setting' => true,
				'label' => $this->_( 'Minutes between collections' ),
				'name' => 'cron_minutes',
				'size' => 5,
				'type' => 'text',
			),
			'reply_to' => array(
				'access_type' => 'edit_general',
				'description' => $this->_( 'This is the main e-mail address of the mailing list, to which are replies are to be sent. If left empty, the replies will go back to the person who sent the mail to the mailing list in the first place.' ),
				'is_setting' => true,
				'label' => $this->_( 'Reply-to address' ),
				'name' => 'reply_to',
				'size' => 50,
				'type' => 'text',
				'validation' => array( 'empty' => true ),
			),
			'administrative_reply_to' => array(
				'access_type' => 'edit_general',
				'description' => $this->_( 'Authorization and moderation requests are sent from this address. This is useful in case you want normal replies to go to a specific person, but authorization and moderation requests to go to the mail daemons address.' ),
				'is_setting' => true,
				'label' => $this->_( 'Administrative reply-to address' ),
				'name' => 'administrative_reply_to',
				'size' => 50,
				'type' => 'text',
				'validation' => array( 'empty' => true ),
			),
			'accept_to' => array(
				'access_type' => 'edit_general',
				'cols' => 50,
				'description' => $this->_( 'In addition to the above reply-to address, accept messages that are sent to the above addresses. Useful when forwarding mails to the list from other email addresses.' ),
				'label' => $this->_( 'Accept mail to these addresses' ),
				'name' => 'accept_to',
				'is_setting' => true,
				'is_emails' => true,
				'rows' => 5,
				'type' => 'textarea',
				'validation' => array( 'empty' => true ),
			),
			'priority_modifier' => array(
				'access_type' => 'edit_general',
				'description' => $this->_( 'Modify the priority of incoming mails with this value. The default modifier is 0.' ),
				'is_setting' => true,
				'label' => $this->_( 'Priority modifier' ),
				'name' => 'priority_modifier',
				'size' => 50,
				'type' => 'text',
			),
			// edit_server
			'server_hostname' => array(
				'name' => 'server_hostname',
				'type' => 'text',
				'size' => 50,
				'label' => $this->_( 'Host name' ),
				'is_setting' => true,
				'access_type' => 'edit_server',
			),
			'server_port' => array(
				'name' => 'server_port',
				'type' => 'text',
				'size' => 5,
				'label' => $this->_( 'Port' ),
				'description' => $this->_( '143 is the standard port for IMAP. 993 is the standard IMAP SSL port.' ),
				'is_setting' => true,
				'access_type' => 'edit_server',
			),
			'server_ssl' => array(
				'name' => 'server_ssl',
				'type' => 'checkbox',
				'label' => $this->_( 'SSL' ),
				'description' => $this->_( 'Use an SSL connection to the server.' ),
				'is_setting' => true,
				'access_type' => 'edit_server',
			),
			'server_novalidate_cert' => array(
				'name' => 'server_novalidate_cert',
				'type' => 'checkbox',
				'label' => $this->_( 'Ignore certificate' ),
				'description' => $this->_( 'Connect to servers with self-signed SSL certificates.' ),
				'is_setting' => true,
				'access_type' => 'edit_server',
			),
			'server_other_options' => array(
				'name' => 'server_other_options',
				'type' => 'text',
				'size' => 50,
				'label' => $this->_( 'Other imap_open settings' ),
				'description' => $this->_( 'See <a href="http://php.net/manual/en/function.imap-open.php">%s</a>. Start your options with a forward slash.', $this->_("imap_open's PHP man page") ),
				'is_setting' => true,
				'validation' => array( 'empty' => true ),
				'access_type' => 'edit_server',
			),
			'server_username' => array(
				'name' => 'server_username',
				'type' => 'text',
				'size' => 50,
				'label' => $this->_( "Username" ),
				'is_setting' => true,
				'access_type' => 'edit_server',
			),
			'server_password' => array(
				'name' => 'server_password',
				'type' => 'text',
				'size' => 50,
				'label' => $this->_( "Password" ),
				'is_setting' => true,
				'access_type' => 'edit_server',
			),
			// edit_modify
			'subject_line_prefix' => array(
				'name' => 'subject_line_prefix',
				'type' => 'text',
				'size' => 50,
				'label' => $this->_( 'Subject line prefix' ),
				'description' => $this->_( "Insert this prefix into the line if it doesn't already exist." ),
				'is_setting' => true,
				'validation' => array( 'empty' => true ),
				'access_type' => 'edit_modify',
			),
			'subject_line_suffix' => array(
				'name' => 'subject_line_suffix',
				'type' => 'text',
				'size' => 50,
				'label' => $this->_( 'Subject line suffix' ),
				'description' => $this->_( "Add this suffix into the subject if it doesn't already exist." ),
				'is_setting' => true,
				'validation' => array( 'empty' => true ),
				'access_type' => 'edit_modify',
			),
			
			// edit_readers_and_writers
			'readers_and_writers_text_1' => array(
				'type' => 'rawtext',
				'value' => '
					<p>' . $this->_( "These sections specify which e-mail addresses may write to the group (writers) and which addresses receive messages (readers).") . '</p>
					<p>' . $this->_( "E-mail addresses are specified one per line. When pasting new addresses they may be comma or space separated on one line. The addresses will be separated and sorted when the settings are saved.") . '</p>
				',
				'access_type' => 'edit_readers_and_writers',
			),
			'readers_and_writers_heading_acceptance_rules' => array(
				'type' => 'rawtext',
				'value' => '<h4>'.$this->_( "Acceptance rules").'</h4>',
				'access_type' => 'edit_readers_and_writers',
			),
			'always_auth' => array(
				'name' => 'always_auth',
				'type' => 'checkbox',
				'label' => $this->_( 'All messages must be authorized' ),
				'description' => $this->_( "This setting forces all messages to be authorized via a reply e-mail. This setting is useful in those situations where the address of the list is known publically, allowing anyone to try to send mail." ),
				'is_setting' => true,
				'access_type' => 'edit_readers_and_writers',
			),
			'non_writer_action' => array(
				'name' => 'non_writer_action',
				'type' => 'select',
				'label' => $this->_( 'Non-writer action' ),
				'description' => $this->_( "What to do with messages that are received from people who are not in any of the writer lists." ),
				'options' => array(
					array( 'value' => '',					'text' => $this->_('Accept') ),
					array( 'value' => 'reject',				'text' => $this->_('Reject') ),
					array( 'value' => 'reject_message',		'text' => $this->_('Reject with message') ),
					array( 'value' => 'auth',				'text' => $this->_('Require authorization') ),
					array( 'value' => 'mod',				'text' => $this->_('Send to the moderators') ),
					array( 'value' => 'auth_mod',			'text' => $this->_('Require authorization then send to the moderators') ),
				),
				'is_setting' => true,
				'access_type' => 'edit_readers_and_writers',
			),
			'invalid_code_action' => array(
				'name' => 'invalid_code_action',
				'type' => 'select',
				'label' => $this->_( 'Action for invalid codes' ),
				'description' => $this->_( "What should the plugin do when an invalid code is received?" ),
				'options' => array(
					'ignore' => $this->_( 'Ignore' ),
					'ignore_with_confirmation' => $this->_( 'Ignore and inform the user that the code was considered invalid' ),
				),
				'is_setting' => true,
				'access_type' => 'edit_readers_and_writers',
			),
			'valid_auth_code_action' => array(
				'name' => 'valid_auth_code_action',
				'type' => 'select',
				'label' => $this->_( 'Action for valid authorization codes' ),
				'description' => $this->_( "What should the plugin do when a valid authorization code is received from a list writer?" ),
				'options' => array(
					'accept' => $this->_( 'Accept' ),
					'accept_with_confirmation' => $this->_( 'Accept and send a confirmation message' ),
				),
				'is_setting' => true,
				'access_type' => 'edit_readers_and_writers',
			),
			'valid_mod_code_action' => array(
				'name' => 'valid_mod_code_action',
				'type' => 'select',
				'label' => $this->_( 'Action for valid moderation codes' ),
				'description' => $this->_( "What should the plugin do when a valid moderation code is received from a moderator?" ),
				'options' => array(
					'accept' => $this->_( 'Accept' ),
					'accept_with_confirmation' => $this->_( 'Accept and send a confirmation message' ),
				),
				'is_setting' => true,
				'access_type' => 'edit_readers_and_writers',
			),
			'readers_and_writers_heading_acceptance_rules' => array(
				'type' => 'rawtext',
				'value' => '<h4>'.$this->_( "Acceptance rules").'</h4>',
				'access_type' => 'edit_readers_and_writers',
			),
			'moderators' => array(
				'access_type' => array( 'edit_readers_and_writers', 'view_readers_and_writers' ),
				'name' => 'moderators',
				'type' => 'textarea',
				'rows' => 15,
				'cols' => 60,
				'label' => $this->_( 'Moderators' ),
				'description' => $this->_( 'The above email adresses are moderators and will each receive the messages that require moderation.' ),
				'is_setting' => true,
				'is_emails' => true,
				'validation' => array( 'empty' => true ),
			),
			'readers_and_writers_heading_writers' => array(
				'type' => 'rawtext',
				'value' => '<h4>'.$this->_( "Writers").'</h4>',
				'access_type' => 'edit_readers_and_writers',
			),
			'writers' => array(
				'access_type' => array( 'edit_readers_and_writers', 'view_readers_and_writers' ),
				'name' => 'writers',
				'type' => 'textarea',
				'rows' => 15,
				'cols' => 60,
				'label' => $this->_( 'Writers' ),
				'description' => $this->_( 'These addresses are either accepted to the list or sent to moderators, depending on whether there are moderators set.' ),
				'is_setting' => true,
				'is_emails' => true,
				'validation' => array( 'empty' => true ),
			),
			'writers_auth' => array(
				'access_type' => array( 'edit_readers_and_writers', 'view_readers_and_writers' ),
				'name' => 'writers_auth',
				'type' => 'textarea',
				'rows' => 15,
				'cols' => 60,
				'label' => $this->_( 'Authorization writers' ),
				'description' => $this->_( "The above writers need to authorize all their messages via a reply e-mail." ),
				'is_setting' => true,
				'is_emails' => true,
				'validation' => array( 'empty' => true ),
			),
			'writers_nomod' => array(
				'access_type' => array( 'edit_readers_and_writers', 'view_readers_and_writers' ),
				'name' => 'writers_nomod',
				'type' => 'textarea',
				'rows' => 15,
				'cols' => 60,
				'label' => $this->_( 'Unmoderated writers' ),
				'description' => $this->_( 'Messages from these email addresses will not be moderated even though there are moderators set.' ),
				'is_setting' => true,
				'is_emails' => true,
				'validation' => array( 'empty' => true ),
			),
			'readers_and_writers_heading_readers' => array(
				'type' => 'rawtext',
				'value' => '<h4>'.$this->_( "Readers").'</h4>',
				'access_type' => 'edit_readers_and_writers',
			),
			'readers' => array(
				'access_type' => array( 'edit_readers_and_writers', 'view_readers_and_writers' ),
				'name' => 'readers',
				'type' => 'textarea',
				'rows' => 15,
				'cols' => 60,
				'label' => $this->_( 'Readers' ),
				'description' => $this->_( 'Readers receieve all messages.' ),
				'is_setting' => true,
				'is_emails' => true,
			),
			// apply
			'save' => array(
				'name' => 'save',
				'type' => 'submit',
				'value' => $this->_( 'Save changes' ),
				'css_class' => 'button-primary',
			),
		) );
	}
	
	/**
		@brief		Return an array of setting types to display.
		@param		$setting_types
						An array of setting types to display to the user when editing a mailing list.
		@return		Array of setting types.
	**/
	public function threewp_email_reflector_admin_edit_get_setting_types( $setting_types )
	{
		return array_merge( $setting_types, array(
			'edit_general' => array(
				'heading' => $this->_( "General settings" ),
			),
			'edit_server' => array(
				'heading' => $this->_( "IMAP server" ),
			), 
			'edit_modify' => array(
				'heading' => $this->_( "Text modifications" ),
			), 
			'edit_readers_and_writers' => array(
				'heading' => $this->_( "Readers and writers" ),
			)
		) );
	}
	
	/**
		@brief		Return an array of settings.
		@param		$inputs
						Array of inputs to modify.
		@return		Array of SD_Form inputs.
	**/
	public function threewp_email_reflector_admin_settings_get_inputs( $inputs )
	{
		// Collect all the roles.
		$roles = array('super_admin' => array('text' => 'Site admin', 'value' => 'super_admin'));
		foreach( $this->roles as $role )
			$roles[$role['name']] = array('value' => $role['name'], 'text' => ucfirst($role['name']));

		$inputs = array_merge( $inputs, array(
			'enabled' => array(
				'description' => $this->_( "Enable global collection and sending of queued messages. If disabled, no lists will be collected at all." ),
				'label' => $this->_( 'Enabled' ),
				'name' => 'enabled',
				'save' => true,
				'setting_type' => 'general',
				'type' => 'checkbox',
				'value' => 1,
			),
			'cron_minutes' => array(
				'description' => $this->_( 'How often, in minutes, to wait between collections (cron). The default is 5.' ),
				'label' => $this->_( 'Cron delay' ),
				'name' => 'cron_minutes',
				'type' => 'text',
				'save' => true,
				'setting_type' => 'general',
				'size' => 5,
				'validation' => array(
					'valuemin' => 1,
				),
			),
			'items_per_page' => array(
				'description' => $this->_( 'How many items to show per page when showing lists that can be paged. Default is 100.' ),
				'label' => $this->_( 'Items per page' ),
				'name' => 'items_per_page',
				'save' => true,
				'setting_type' => 'general',
				'size' => 5,
				'type' => 'text',
				'validation' => array(
					'valuemin' => 1,
				),
			),
			'old_age' => array(
				'description' => $this->_( 'How many days to keep codes and messages in the queue. The default is 7.' ),
				'label' => $this->_( 'Old age' ),
				'name' => 'old_age',
				'save' => true,
				'setting_type' => 'general',
				'size' => 3,
				'type' => 'text',
				'validation' => array(
					'valuemin' => 1,
				),
			),
			'priority_decrease_per' => array(
				'description' => $this->_( 'Decrease the priority of a batch by one per every x readers. The default value of 50 decreases the priority of a batch by one per every 50 readers.' ),
				'label' => $this->_( 'Priority decrease batch size' ),
				'name' => 'priority_decrease_per',
				'setting_type' => 'general',
				'save' => true,
				'size' => 4,
				'type' => 'text',
				'validation' => array(
					'valuemin' => 0,
				),
			),
			'collection_connections' => array(
				'description' => $this->_( 'Number of simultaneous connections. Set to 1 to only collect from one server at a time. 0 is unlimited. The default is 10.' ),
				'name' => 'collection_connections',
				'label' => $this->_( 'Connections' ),
				'save' => true,
				'setting_type' => 'collection',
				'size' => 5,
				'type' => 'text',
				'validation' => array(
					'valuemin' => 0,
				),
			),
			'collection_connections_per_server' => array(
				'description' => $this->_( 'Maximum amount of connections per each unique server name. 0 is unlimited. The default is 10.' ),
				'label' => $this->_( 'Connections per server' ),
				'name' => 'collection_connections_per_server',
				'save' => true,
				'setting_type' => 'collection',
				'size' => 5,
				'type' => 'text',
				'validation' => array(
					'valuemin' => 0,
				),
			),
			'curl_follow_location' => array(
				'description' => $this->_( "Depending on your .htaccess settings, you might need the CURL fetchers to follow redirects. Enable this if mails aren't being queued but not sent." ),
				'label' => $this->_( 'CURL follows redirects' ),
				'name' => 'curl_follow_location',
				'save' => true,
				'setting_type' => 'general',
				'type' => 'checkbox',
				'value' => 1,
			),
			'send_batch_connections' => array(
				'description' => $this->_( 'How many emails to send simultaneously.' ),
				'label' => $this->_( 'Send connections' ),
				'name' => 'send_batch_connections',
				'save' => true,
				'setting_type' => 'send',
				'size' => 5,
				'type' => 'text',
				'validation' => array(
					'valuemin' => 1,
				),
			),
			'send_batch_size' => array(
				'description' => $this->_( 'How many emails to send per batch (cron). The default is 25.' ),
				'label' => $this->_( 'Batch size' ),
				'name' => 'send_batch_size',
				'save' => true,
				'setting_type' => 'send',
				'size' => 5,
				'type' => 'text',
				'validation' => array(
					'valuemin' => 1,
				),
			),
			'event_key' => array(
				'description' => $this->_( "Secret event key used when checking incoming events." ),
				'label' => $this->_( 'Event key' ),
				'name' => 'event_key',
				'save' => true,
				'setting_type' => 'security',
				'size' => 40,
				'type' => 'text',
			),
			'security_role_to_use' => array(
				'description' => $this->_( "Which role is needed to use the mailing list at all." ),
				'label' => $this->_( 'Role to use the mailing list' ),
				'name' => 'security_role_to_use',
				'options' => $roles,
				'save' => true,
				'setting_type' => 'security',
				'type' => 'select',
			),
			'security_all_lists_visible' => array(
				'description' => $this->_( "If enabled will display all the existing mailing lists in the overview, even if the user isn't allowed to edit them." ),
				'label' => $this->_( 'Show all the lists to all users' ),
				'name' => 'security_all_lists_visible',
				'save' => true,
				'setting_type' => 'security',
				'type' => 'checkbox',
				'value' => 1,
			),
			'save' => array(
				'css_class' => 'button-primary',
				'name' => 'save',
				'value' => $this->_( 'Save new settings' ),
				'type' => 'submit',
			),
		) );
		
		return $inputs;
	}
	
	/**
		@brief		Return an array of setting types to display.
		@param		$setting_types
						An array of setting types to display to the user when editing a mailing list.
		@return		Array of setting types.
	**/
	public function threewp_email_reflector_admin_settings_get_setting_types( $setting_types )
	{
		return array_merge( $setting_types, array(
			'general' => array(
				'heading' => $this->_( "General" ),
			),
			'send' => array(
				'heading' => $this->_( "Send" ),
			), 
			'collection' => array(
				'heading' => $this->_( "Collection" ),
			), 
			'security' => array(
				'heading' => $this->_( "Security " ),
			)
		) );
	}
	
	/**
		@brief		Modify the tab data at the top of the admin interface.
		@param		$tab_data
						Tab data array to modify.
		@return		Tab data array.
	**/
	public function threewp_email_reflector_admin_get_tab_data( $tab_data )
	{
		$tab_data['tabs']['admin_overview'] = $this->_( 'Overview');
		$tab_data['functions']['admin_overview'] = 'admin_overview';
		
		if ( isset($_GET['tab']) && isset($_GET['id']) )
		{
			if ( $_GET['tab'] == 'delete' )
			{
				$list_id = $_GET['id'];
				$settings = $this->threewp_email_reflector_get_list_settings( $list_id );
				$tab_data['page_titles']['delete'] = $this->_( 'Delete %s', $settings->list_name . ' <small>- '.$settings->reply_to.'</small>' );
				$tab_data['tabs']['delete'] = $this->_( 'Delete');
				$tab_data['functions']['delete'] = 'admin_delete';
			}
			
			if ( $_GET['tab'] == 'access' )
			{
				$list_id = $_GET['id'];
				$settings = $this->threewp_email_reflector_get_list_settings( $list_id );
				$tab_data['page_titles']['access'] = $this->_( 'Editing access for %s', $settings->list_name . ' <small>- '.$settings->reply_to.'</small>' );
				$tab_data['tabs']['access'] = $this->_( 'Access' );
				$tab_data['functions']['access'] = 'admin_access';
			}
			
			if ( $_GET['tab'] == 'edit' )
			{
				$list_id = $_GET['id'];
				$settings = $this->threewp_email_reflector_get_list_settings( $list_id );
				$tab_data['page_titles']['edit'] = $this->_( 'Editing %s', $settings->list_name . ' <small>- '.$settings->reply_to.'</small>' );
				$tab_data['tabs']['edit'] = $this->_( 'Edit' );
				$tab_data['functions']['edit'] = 'admin_edit';
			}
			
			if ( $_GET['tab'] == 'collect' )
			{
				$tab_data['tabs']['collect'] = $this->_( 'Collect');
				$tab_data['functions']['collect'] = 'admin_collect';
			}

			if ( $_GET['tab'] == 'user_access' )
			{
				$user_id = $_GET['id'];
				$user = get_userdata( $user_id );
				if ( $user === false )
					wp_die('Incorrect user ID.');

				$tab_data['page_titles']['user_access'] = $this->_( 'Edit list access for %s', $user->user_login . ' <small>- '.$user->user_email.'</small>' );
				$tab_data['tabs']['user_access'] = $this->_( 'User access' );
				$tab_data['functions']['user_access'] = 'admin_user_access';
			}

			if ( $_GET['tab'] == 'view_readers_and_writers' )
			{
				$list_id = $_GET['id'];
				$settings = $this->threewp_email_reflector_get_list_settings( $list_id );
				$tab_data['page_titles']['view_readers_and_writers'] = $this->_( 'Viewing readers and writers for %s', $settings->list_name . ' <small>- '.$settings->reply_to.'</small>' );
				$tab_data['tabs']['view_readers_and_writers'] = $this->_( 'View readers and writers' );
				$tab_data['functions']['view_readers_and_writers'] = 'admin_view_readers_and_writers';
			}
		}
		
		if ($this->is_admin)
		{
			foreach( array(
				'admin_mass_edit' => $this->_( 'Mass edit' ),
				'admin_queue' => $this->_( 'Queue' ),
				'admin_codes' => $this->_( 'Codes' ),
				'admin_settings' => $this->_( 'Settings' ),
				'admin_statistics' => $this->_( 'Statistics' ),
				'admin_uninstall' => $this->_( 'Uninstall' ),
			) as $key => $value )
			{
				$tab_data['tabs'][ $key ] = $value;
				$tab_data['functions'][ $key ] = $key;
			}
		}
		
		return $tab_data;
	}
	
	/**
		@brief		Return a list of access types.
		@param		$access_types
						Array of access types.
		@return		Array of access types. 
	**/
	
	public function threewp_email_reflector_get_access_types( $access_types )
	{
		return array_merge( $access_types, array(
			array(
				'name' => 'see_in_overview',
				'label' => $this->_( 'See in overview' ),
			),
			array(
				'editable' => true,
				'name' => 'collect',
				'label' => $this->_( 'Collect' ),
				'title' => $this->_( 'Allow user to start a collection.' ),
			),
			array(
				'editable' => true,
				'name' => 'edit_general',
				'label' => $this->_( 'General settings' ),
			),
			array(
				'editable' => true,
				'name' => 'edit_server',
				'label' => $this->_( 'IMAP settings' ),
			),
			array(
				'editable' => true,
				'name' => 'edit_modify',
				'label' => $this->_( 'Text settings' ),
			),
			array(
				'editable' => true,
				'name' => 'edit_readers_and_writers',
				'label' => $this->_( 'Edit readers and writers' ),
			),
			array(
				'action' => 'view',
				'name' => 'view_readers_and_writers',
				'label' => $this->_( 'View readers and writers' ),
			),
		) );
	}
	
	/**
		@brief		Populate the list info array with info from the list.
		@return		Array of info strings.
	**/
	public function threewp_email_reflector_admin_overview_get_list_info( $info, $list_settings )
	{
		if ( $list_settings->stats_mails_reflected > 0 )
			$info[] =  $this->_( 'Messages reflected: %s', $list_settings->stats_mails_reflected );
		
		if ( $list_settings->stats_mails_ignored > 0 )
			$info[] =  $this->_( 'Messages ignored: %s', $list_settings->stats_mails_ignored );
		
		return $info;
	}
	
	/**
		@brief		Handles the discarding of a message.
		
		The $options object will contain at least the following variables:
		
		- bool $accepted Was the message accepted and sent to the readers?
		- bool $handled Was the message handled, causing the message to be either accepted or sent for moderation or what not?
		- int $msgno The IMAP message number of the message
		- bool $processed Was the message processed (sent to people, sent back for authorization, etc) or was it just discarded completely because we sent it to ourselves?
		- object $settings The ThreeWP_Email_Reflector_Settings object for this list
		- object $stream The IMAP stream
		
		Note: if ! $processed, then $accepted and $handled will not exist.
		
		After deleting the message, will set the variable ->deleted to true.
		
		@param		object		$options		An object containing info about the message.
	**/
	public function threewp_email_reflector_discard_message( $options )
	{
		imap_delete( $options->stream, $options->msgno );
		$options->deleted = true;
		$this->log( 'imap', $this->_( 'Message %s deleted in <em>%s</em>.', $options->msgno, $options->settings->list_name ) );
		return $options;
	}

	/**
		@brief		Return an array of log types.
		@param		$log_types
						An array of log types that Email Reflector produces.
		@return		Array of log types.
	**/
	public function threewp_email_reflector_get_log_types( $log_types )
	{
		return array_merge( $log_types, array(
			'code' => array(
				'label' => $this->_( 'Identification and processing of codes' )
			),
			'collect' => array(
				'label' => $this->_( 'Collection of mail from the various servers' ),
			),
			'cron' => array(
				'label' => $this->_( 'Periodical maintenance functions (<em>cron</em>)' ),
			),
			'curl' => array(
				'label' => $this->_( 'CURL handling (sending and fetching of messages)' ),
			),
			'imap' => array(
				'label' => $this->_( 'IMAP calls' ),
			),
			'mail' => array(
				'label' => $this->_( 'Sending of mail' ),
			),
			'process' => array(
				'label' => $this->_( 'Processing of incoming mail' ),
			),
			'queue' => array(
				'label' => $this->_( 'Queue actions' ),
			),
		) );
	}

	/**
		@brief		Return an array of lists.
		@return		Array of lists.
	**/
	public function threewp_email_reflector_get_lists()
	{
		return $this->query("SELECT * FROM `".$this->wpdb->base_prefix."3wp_email_reflector_lists`");
	}
	
	
	/**
		@brief		Retrieve the settings of a list.
		
		@param		$list_id
						ID of list's settings to retrieve.
		
		@return		Settings object.
	**/
	public function threewp_email_reflector_get_list_settings( $list_id )
	{
		$rv = new ThreeWP_Email_Reflector_Settings();

		$rv->list_id = $list_id;
		
		$results = $this->query("SELECT `key`, `value` FROM `".$this->wpdb->base_prefix."3wp_email_reflector_list_settings` WHERE list_id = '$list_id'");
		$values = array();
		foreach( $results as $result )
		{
			$key = $result['key'];
			$rv->$key = $result['value'];
		}
		
		return $rv;
	}
	
	/**
		@brief		Return an array of lists, including their complete settings.
		@return		Array of lists with settings.
	**/
	public function threewp_email_reflector_get_lists_with_settings()
	{
		$lists = $this->threewp_email_reflector_get_lists();
		
		// Sort the list using the list_name, put into the array as the key.
		foreach( $lists as $index => $list )
		{
			$settings = $this->threewp_email_reflector_get_list_settings( $list['list_id'] );
			$lists[$index] = array_merge( $list, (array)$settings );
			$lists[$index]['settings'] = $settings;
		}
		$lists = $this->array_rekey($lists, 'list_name');
		ksort( $lists );
		return $lists;
	}
	
	/**
		@brief		Returns an option (global setting) from Email Reflector.
		@param		$option
						Option to retrieve.
		@return		The option requested, else false.
	**/
	public function threewp_email_reflector_get_option( $option )
	{
		return $this->get_site_option( $option );
	}
	
	/**
		@brief		Returns how many mails are in the send queue.
		@return		Count of mails in the send queue.
	**/
	public function threewp_email_reflector_get_queue_size( )
	{
		$result = $this->query("SELECT COUNT(*) AS count FROM `".$this->wpdb->base_prefix."3wp_email_reflector_queue`");
		return $result[0]['count'];
	}
	
	/**
		@brief		Handles an event from the event driver.
		
		Checks the _REQUEST for the correct variables, finds out which lists use the e-mail addresses and then processes them.
	**/
	public function threewp_email_reflector_handle_event()
	{
		if ( ! isset( $_REQUEST[ 'to' ] ) )
			wp_die( 'No line.' );
		if ( ! isset( $_REQUEST[ 'hash' ] ) )
			wp_die( 'No hash.' );
		if ( ! isset( $_REQUEST[ 'rand' ] ) )
			wp_die( 'No random.' );
		
		$to = $this->check_plain( $_REQUEST[ 'to' ] );
		$hash = $this->check_plain( $_REQUEST[ 'hash' ] );
		$rand = $this->check_plain( $_REQUEST[ 'rand' ] );
		
		// Random should contain at least two characters.
		if ( strlen( $rand ) < 2 )
			wp_die( 'Random is too short.' );
		
		$key = $this->get_site_option( 'event_key', time() );		// Assume a default value that will never work out.
		
		// Check that the hash is ok
		$md5 = md5( $to . $rand . $key );
		// Cut the md5 off to 4 chars
		$md5 = substr( $md5, 0, 4 );
		
		if ( $md5 != $hash )
			wp_die( 'Hash is not correct.' );
		
		$rv = array();
		$to = array_filter( explode( ',', $to ) );
		
		$process_queue = false;
			
		// Excellent! Find the list(s) that accept this email address and process them.
		$lists = $this->threewp_email_reflector_get_lists_with_settings();
		
		foreach( $lists as $list )
		{
			$settings = $list[ 'settings' ];
			
			if ( ! $settings->enabled() )
				continue;
			
			$tos = $settings->accept_tos();
			if ( count( array_intersect( $to, $tos ) ) < 1 )
				continue;
			
			$this->filters( 'threewp_email_reflector_process_list', $settings->list_id );
			$process_queue = true;
			$rv[] = $this->p( 'Processing <em>%s</em>.', $settings->list_name );
		}
		
		if ( $process_queue )
			$this->process_queue();
		$this->message( implode( '<br/>', $rv ) );
	}

	/**
		@brief		Wrapper for log method.
		
		@param		$type
						Type of log message.
		
		@param		$message
						Log message itself.

		@see		log
	**/
	public function threewp_email_reflector_log( $type, $message )
	{
		return $this->log( $type, $message );
	}

	/**
		@brief		Process a list.
		
		@param		int		$list_id		ID of list to process.
	**/
	public function threewp_email_reflector_process_list( $list_id )
	{
		$settings = $this->threewp_email_reflector_get_list_settings( $list_id );
		$stream = $this->imap_connect( $settings );
		
		if ($stream === false)
		{
			$this->log( 'imap', $this->_( 'IMAP stream <em>%s</em> could not be opened.', $settings->list_name ) );
			exit;
		}
		
		$this->log( 'process', $this->_( 'Processing <em>%s</em>.', $settings->list_name ) );
		
		$options = new ThreeWP_Email_Reflector_Process_Options();
		$options->list_id = $list_id;
		$options->stream = $stream;
		$options->settings = $settings;
		$this->process( $options );
		
		imap_close( $stream );

		$this->threewp_email_reflector_update_list_setting( $list_id, 'stats_last_collected', $this->now() );
	}
	
	/**
		@brief		Returns an option (global setting).

		@param		$option
						Option to update.

		@param		$value
						New value.

		@return		The option requested, else false.
	**/
	public function threewp_email_reflector_update_option( $option, $value )
	{
		$this->update_site_option( $option, $value );
	}
	
	/**
		@brief		Returns an option (global setting).

		@param		$option
						Option to update.

		@param		$value
						New value.

		@return		The option requested, else false.
	**/
	public function threewp_email_reflector_update_list_setting( $list_id, $key, $value )
	{
		if ( $this->sql_get_setting( $list_id, $key ) === false )
			$this->query("INSERT INTO `".$this->wpdb->base_prefix."3wp_email_reflector_list_settings` (`list_id`, `key`, `value`) VALUE ('$list_id', '$key', '$value')");
		else
			$this->query("UPDATE `".$this->wpdb->base_prefix."3wp_email_reflector_list_settings` SET
				`value` = '$value'
				WHERE list_id = '$list_id' AND `key` = '$key'");
	}
	
	// --------------------------------------------------------------------------------------------
	// ----------------------------------------- Misc functions
	// --------------------------------------------------------------------------------------------
	
	protected function accept_code( $code, $accept_options = null )
	{
		if( $accept_options === null )
			$accept_options = new stdClass();

		$message = $this->sql_get_code( $code );
		$message = $this->sql_decode( $message['message_data'] );
		
		// Update the settings for this message's list.
		$list_id = $message->list_id;
		$settings = $this->threewp_email_reflector_get_list_settings( $list_id );
		$message->settings = $settings;	// Save the settings for passing it to process_message later.
		$to = $this->assemble_address( $message->headerinfo->to );		
		$from = $this->assemble_address( $message->headerinfo->from );		
		$all_writers = $this->all_writers( $settings );
		
		// How to handle this message.
		switch ( $message->code_type )
		{
			case 'auth':
				// This message is now authorized. Therefore, no more authorization is needed.
				$settings->always_auth = false;
				$settings->writers = $all_writers;
				$settings->writers_auth = '';
				
				// Should we tell the user that his message was authorized?
				if ( $settings->valid_auth_code_action == 'accept_with_confirmation' )
				{
					$valid_options = new stdClass();
					$valid_options->to = $from;			// We're sending the message to the person who sent in the code, not to the list.
					$valid_options->settings = $settings;
					$valid_options->code = $code;
					$this->process_valid_auth_code_message( $valid_options );
				}
				
				break;
			case 'mod':
				// This message is now moderated.
				$settings->always_auth = false;
				$settings->writers = '';
				$settings->writers_auth = '';
				$settings->moderators = '';

				// Should we tell the moderator that the message was accepted?
				if ( $settings->valid_mod_code_action == 'accept_with_confirmation' )
				{
					$valid_options = new stdClass();
					$valid_options->to = $accept_options->from;		// We're sending the message to the person who sent in the code, not to the list.
					$valid_options->settings = $settings;
					$valid_options->code = $code;
					$valid_options->message = $message;
					$this->process_valid_mod_code_message( $valid_options );
				}
				
				break;
		}
		
		if ( ! $this->contains_email( $all_writers, $from ) )
		{
			switch ( $settings->non_writer_action )
			{
				case 'auth':
					if ( $message->code_type == 'auth' )
						$settings->non_writer_action = '';
					break;
				case 'auth_mod':
					// Message has just been authorized
					if ( $message->code_type == 'auth' )
						$settings->non_writer_action = 'mod';
					// Message has just been moderated
					if ( $message->code_type == 'mod' )
						$settings->non_writer_action = '';
					break;
				case 'mod':
					// Message has just been moderated. Accept it!
					if ( $message->code_type == 'mod' )
						$settings->non_writer_action = '';
					break;
			}
		}
		
		$this->process_message( $message );
		
	}
	
	protected function schedule_collection()
	{
		$cron_minutes = $this->get_site_option( 'cron_minutes' );
		wp_schedule_single_event( time() + $cron_minutes * 60, 'threewp_email_reflector_cron' );
	}
	
	/**
		Recognizes if this message is from the list itself.
		
		This check prevents circle-jerks.
		
		@param		$options	Message options.
		@return		True if the message is from ourselves.
	**/
	protected function message_is_from_ourselves( $options )
	{
		$from = $this->assemble_address( $options->headerinfo->from );
		return ( $from == $options->settings->administrative_address() );
	}
	
	protected function process( $options )
	{
		$stream = $options->stream;			// Conv
		$settings = $options->settings;		// Conv
		$verbose = $options->verbose;		// Conv
		
		// Find all the messages
		$messages = imap_headers( $stream );
		
		if ( $verbose )
			$this->message( $this->_('%s messages found.', count( $messages ) ) );
		
		$this->log( 'process', $this->_( '%s messages found in <em>%s</em>.', count( $messages ), $settings->list_name ) );

		foreach( $messages as $index => $message )
		{
			$msgno = $index+1;
			$message_options = clone( $options );
			$message_options->msgno = $msgno;
			$message_options->headerinfo = imap_headerinfo( $stream, $msgno );
			$message_options->header = imap_fetchheader( $stream, $msgno );
			$message_options->structure = imap_fetchstructure( $stream, $msgno );
			$message_options->body = imap_body( $options->stream, $msgno );

			if ( ! $this->message_is_from_ourselves( $message_options ) )
			{
				if ( ! $this->message_is_code( $message_options ) )
					$this->process_message( $message_options );
				$message_options->processed = true;
			}
			else
			{
				$from = $this->assemble_address( $message_options->headerinfo->from );
				$this->log( 'process', $this->_( 'Message %s to <em>%s</em> was from <em>%s</em> and therefore invalid.', $msgno, $settings->list_name, $from ) );
				$message_options->processed = false;
			}
			
			$this->filters( 'threewp_email_reflector_discard_message', $message_options );			
		}
		imap_expunge( $stream );

		$this->log( 'imap', $this->_( 'Expunged <em>%s</em>.', $settings->list_name ) );
	}
	
	/**
		Decide what to do with this message.
		
		@param	$options	ThreeWP_Email_Reflector_Process_Options object.
	**/
	protected function process_message( $options )
	{
		$options->handled = false;				// Has this message been handled by us?
		$headerinfo = $options->headerinfo;		// Convenience
		$settings = $options->settings;			// Convenience
				
		$from = $this->assemble_address( $headerinfo->from );
		
		// Is this message for us?
		if ( $this->addressed_to_list( $options ) )
		{
			$options->accepted = false;
			$need_auth = false;
			$need_moderation = false;
			$moderated = ( $settings->moderators != '' );
			$all_writers = $this->all_writers( $settings );
			
			// 1. If this message needs authorization, code it and send it back.
			if ( $settings->always_auth )
			{
				if ( $options->verbose )
					$this->message( $this->_('This message must be authorized.') );
				$this->create_authorization_message( $options );
				$options->handled = true;
			}
			
			// 2. Does just this writer need authorization?
			if ( ! $options->handled )
			{
				if ( $this->contains_email( $settings->writers_auth, $from ) )
				{
					if ( $options->verbose )
						$this->message( $this->_('This message must be authorized.') );
					
					$this->create_authorization_message( $options );
					$options->handled = true;
				}
			}
			
			// 3. Need to moderate from known writers?
			if ( ! $options->handled && $moderated && $this->contains_email( $all_writers, $from ) )
			{
				$options->handled = true;
				
				// Is the person a moderator?
				if ( $this->contains_email( $settings->moderators, $from ) )
				{
					if ( $options->verbose )
						$this->message( $this->_('This message is from a moderator.') );
					
					$options->accepted = true;
				}
				else
				{
					// Is the person unmoderated?
					if ( $this->contains_email( $settings->writers_nomod, $from ) )
					{
						if ( $options->verbose )
							$this->message( $this->_('This message is from a non-moderated writer.') );
						
						$options->accepted = true;
					}
					else
					{
						if ( $options->verbose )
							$this->message( $this->_('This message will be moderated.') );
						
						$this->create_moderation_message( $options );
					}
				}
			}
			
			// 4. Leftovers: unknown writers.
			if ( ! $options->handled )
			{
				// #4 pretty much always handles mail.
				$options->handled = true;
				if ( strlen( $all_writers ) > 0 )
				{
					// Is this person a writer?
					if ( $this->contains_email( $all_writers, $from ) )
					{
						$options->accepted = true;
					}
					else
					{
						// There are writers specified, but this person isn't one.

						if ( $options->verbose )
							$this->message( $this->_('This message is not from a known writer.') );
						
						switch ( $settings->non_writer_action )
						{
							case 'auth':
							case 'auth_mod':
								if ( $options->verbose )
									$this->message( $this->_('This message must be authorized.') );
								
								$this->create_authorization_message( $options );
								break;
							case 'mod':
								if ( $moderated )
								{
									if ( $options->verbose )
										$this->message( $this->_('This message must be moderated.') );
									
									$this->create_moderation_message( $options );
								}
								else
									$options->handled = false;
								break;
							case 'reject_message':
								if ( $options->verbose )
									$this->message( $this->_('This message will be rejected with a message.') );
								
								$this->create_rejection_message( $options );

								$options->handled = false;
								break;
							case 'reject':
								// Rejected are NOT handled.

								if ( $options->verbose )
									$this->message( $this->_('This message will be rejected.') );
								
								$options->handled = false;
								break;
							default:
								if ( $options->verbose )
									$this->message( $this->_('This message will be accepted.') );
								
								$options->accepted = true;
								break;
						}
					}
				} // if ( strlen( $all_writers ) > 0 )
				else
				{
					// 5. List is open. Send it!
					$options->accepted = true;
				}
			}
			
			if ( $options->accepted )
			{
				if ( $options->verbose )
					$this->message( $this->_('Queueing message from <em>%s</em> to <em>%s</em>.', $from, $settings->list_name ) );
				
				$this->log( 'queue', $this->_( 'Queueing message from <em>%s</em> to <em>%s</em>.', $from, $settings->list_name ) );
				
				$this->queue_message( $options );
				
				// Increase the stats
				$this->threewp_email_reflector_update_list_setting( $options->list_id, 'stats_mails_reflected',
					$this->sql_get_setting( $options->list_id, 'stats_mails_reflected' ) + 1
				);
			}
		}
		
		if ( ! $options->handled )
		{
			if ( $options->verbose )
				$this->message( $this->_('Ignoring message from <em>%s</em>.', $from ) );
			
			$this->log( 'code', $this->_( 'Ignoring message from <em>%s</em> to <em>%s</em>.', $from, $settings->list_name ) );
			
			$this->threewp_email_reflector_update_list_setting( $options->list_id, 'stats_mails_ignored',
				$this->sql_get_setting( $options->list_id, 'stats_mails_ignored' ) + 1
			);
		}
	}
	
	protected function create_authorization_message( $options )
	{
		$code_options = clone( $options );
		$code_options->code_type = 'auth';
		return $this->send_code( $code_options );
	}
	
	/**
		Makes and sends a message about an invalid code.
		
		The options variable is a stdClass containing the following:
		
		- @b	code		The code string itself.
		- @b	settings	The settings class for this list.
		- @b	to			The email address to which to send the message.
		
		@param	$options	A stdClass of options.
	**/
	protected function process_invalid_code_message( $options )
	{
		$from_email = $options->settings->administrative_address();
		$mail_data = array(
			'from' => array( $from_email => $options->settings->list_name ),
			'to' => array( $options->to ),
			'subject' => $this->_( 'Invalid code sent to %s', $options->settings->list_name ),
			'body_html' => '
			
			<p>'. $this->_( 'You tried to send an invalid code to the mailing list <em>%s</em>.', $options->settings->list_name ) .'</p>
			
			<p>'. $this->_( 'The code you tried to send was <em>%s</em>.', $options->code ) .'</p>
			
			<p>'. $this->_( 'Either there was no such code or an autoreply function has already replied to the message containing the code.', $options->settings->list_name ) .'</p>', 
		);

		$result = $this->send_mail( $mail_data );
		if ($result === true)
			$this->log( 'mail', $this->_( 'Invalid code message was sent to <em>%s</em> for <em>%s</em>.', $options->to, $options->settings->list_name ) );
		else
			$this->log( 'mail', $this->_( 'Could not send invalid code message to <em>%s</em> for <em>%s</em>.', $options->to, $options->settings->list_name ) );
	}
	
	protected function create_moderation_message( $options )
	{
		$code_options = clone( $options );
		$code_options->code_type = 'mod';
		return $this->send_code( $code_options );
	}
	
	protected function create_rejection_message( $options )
	{
		$settings = $options->settings;			// Conv
		$headerinfo = $options->headerinfo;		// Conv
		$to = $this->assemble_address( $headerinfo->from );
		
		$from_email = $settings->administrative_address();
		$mail_data = array(
			'from' => array( $from_email => $settings->list_name ),
			'to' => array( $to ),
			'subject' => $this->_( 'Your message was rejected.' ),
			'body_html' => '
			<p>'. $this->_( 'Your message to the mailing list <em>%s</em> was rejected because the list did not recognize you as a valid writer.', $settings->list_name ) .'</p>
			', 
		);
		$result = $this->send_mail( $mail_data );
		if ($result === true)
			$this->log( 'mail', $this->_( 'Rejection message was sent to <em>%s</em> for <em>%s</em>.', $to, $settings->list_name ) );
		else
			$this->log( 'mail', $this->_( 'Could not send rejection message to <em>%s</em> for <em>%s</em>.', $to, $settings->list_name ) );
	}
	
	/**
		Makes and sends a message about a valid authorization code.
		
		The options variable is a stdClass containing the following:
		
		- @b	code		The code string itself.
		- @b	settings	The settings class for this list.
		- @b	to			The email address to which to send the message.
		
		@param	$options	A stdClass of options.
	**/
	protected function process_valid_auth_code_message( $options )
	{
		$from_email = $options->settings->administrative_address();
		$mail_data = array(
			'from' => array( $from_email => $options->settings->list_name ),
			'to' => array( $options->to ),
			'subject' => $this->_( 'Your message to %s is now authorized', $options->settings->list_name ),
			'body_html' => '
			
			<p>'. $this->_( 'The message you sent to <em>%s</em> has now been authorized.', $options->settings->list_name ) .'</p>
			
			<p>
				'. $this->_( 'If the list is moderated your message will now be sent to the moderators. If not, the readers will soon receive a copy of your message.' ) . '
			</p>
			
			',
		);

		$result = $this->send_mail( $mail_data );
		if ($result === true)
			$this->log( 'mail', $this->_( 'Authorization confirmation message was sent to <em>%s</em> for <em>%s</em>.', $options->to, $options->settings->list_name ) );
		else
			$this->log( 'mail', $this->_( 'Could not send authorization confirmation message to <em>%s</em> for <em>%s</em>.', $options->to, $options->settings->list_name ) );
	}
	
	/**
		Makes and sends a message about a valid moderation code.
		
		The options variable is a stdClass containing the following:
		
		- @b	code		The code string itself.
		- @b	moderator	The email address of the moderator that accepted the message.
		- @b	settings	The settings class for this list.
		
		@param	$options	A stdClass of options.
	**/
	protected function process_valid_mod_code_message( $options )
	{
		$from_email = $options->settings->administrative_address();
		$mail_data = array(
			'from' => array( $from_email => $options->settings->list_name ),
			'to' => array( $options->to ),
			'subject' => $this->_( "You have accepted a message to %s", $options->settings->list_name ),
			'body_html' => '
			
			<p>'. $this->_( 'You have accepted the message sent from <em>%s</em> to the mailing list <em>%s</em>.',
					$this->assemble_address( $options->message['headerinfo']->from ),
					$options->settings->list_name
				) .'</p>

			<p>
				'. $this->_( 'The message has been placed in the send queue and depending on how long the queue is the readers should receive a copy of the message shortly.' ) .'
			</p>
			',
		);
		
		$result = $this->send_mail( $mail_data );
		if ($result === true)
			$this->log( 'mail', $this->_( 'Authorization confirmation message was sent to <em>%s</em> for <em>%s</em>.', $options->to, $options->settings->list_name ) );
		else
			$this->log( 'mail', $this->_( 'Could not send authorization confirmation message to <em>%s</em> for <em>%s</em>.', $options->to, $options->settings->list_name ) );
	}
	
	protected function send_code( $options )
	{
		$code = $this->hash( serialize($options), 'sha256' );
		$code = substr( $code, 0, 32 );
		$this->sql_add_code( $code, $options );
		
		$headerinfo = $options->headerinfo;		// Conv
		$settings = $options->settings;			// Conv
		
		switch( $options->code_type )
		{
			case 'mod':
				$request_type = $this->_( 'Moderation request' );
				$to = explode( "\n", $settings->moderators );
				$subject = $this->_( 'Please moderate this message:') . ' ' . '3wper' . $code;
				$body = '<p>'. $this->_( 'A new message to <em>%s</em> was sent by <em>%s</em>.',
					$settings->list_name, $this->assemble_address( $options->headerinfo->from )
					) .' </p> 
				
				<p>'. $this->_( 'To accept the message, reply to this email and immediately press send. If you ignore this email the message will not be accepted into the list.') .' </p>

				<p>'. $this->_( 'The original message is attached') .' </p>
';
				break;
			case 'auth':
				$request_type = $this->_( 'Authorization request' );
				$to = array( $this->assemble_address( $headerinfo->from) );
				$subject = $this->_( 'Your message needs authorization:') . ' ' . '3wper' . $code;
				$body = '<p>'. 	$this->_( 'Your message to the mailing list <em>%s</em> must be authorized.', $settings->list_name ) .' </p> 
				
				<p>'. $this->_( 'To authorize the message, reply to this email and immediately press send.') .' </p>

				<p>'. $this->_( 'The original message is attached') .' </p>
';
				break;
		}
		
		// Save the original message to disk, in order for it to be attached.
		$tempfile = tempnam( '/tmp', 'codemessage_' );
		$tempfilename = $this->assemble_address( $headerinfo->from) . '.txt';
		file_put_contents( $tempfile, imap_qprint( $options->body ) );
		
		$from_email = $settings->administrative_address();
		$mail_data = array(
			'from' => array( $from_email => $settings->list_name ),
			'to' => $to,
			'attachments' => array(
				$tempfile => $tempfilename,
			),
			'subject' => $subject,
			'body_html' => $body,
		);

		$result = $this->send_mail( $mail_data );
		
		unlink( $tempfile );
		
		$from = $this->assemble_address( $headerinfo->from);
		if ($result === true)
			$this->log( 'mail', $this->_( '%s sent from <em>%s</em> for <em>%s</em>. Code: <em>%s</em>',
				$request_type,
				$from,
				$settings->list_name,
				$code
			) );
		else
			$this->log( 'mail', $this->_( 'Could not send %s from <em>%s</em> for <em>%s</em>. Code: <em>%s</em>',
				$this->strtolower( $request_type ),
				$from,
				$settings->list_name,
				$code
			) );
		
		return $result;
	}
	
	protected function queue_message( $options )
	{
		$msgno = $options->msgno;			// Conv
		$settings = $options->settings;		// Conv

		// Fetch the message
		$structure = $options->structure;	// Conv
		
		// Reports = undeliverable. Too bad. Don't queue it.
		if ( $structure->subtype == 'report' )
			return;
		
		// Modify the subject.
		$subject = $options->headerinfo->subject;
		$subject = iconv_mime_decode( $subject, 0, 'UTF-8' );		// mb_encode_mimeheader leaves underscores, therefore imap_utf8
		if ( $settings->subject_line_prefix != '' )
		{
			$prefix = $settings->subject_line_prefix;
			if ( strpos( $subject, $prefix ) === false )
				$subject = $prefix . $subject;
		}
		
		if ( $settings->subject_line_suffix != '' )
		{
			$suffix = $settings->subject_line_suffix;
			if ( strpos( $subject, $suffix ) === false )
				$subject .= $suffix;
		}
		mb_internal_encoding('UTF-8');
		$subject = mb_encode_mimeheader( $subject, 'UTF-8' ) ;
		
		$headers = trim( $options->header );

		// Remove stuff from the header that would conflict with our new headers.
		$headers_to_remove = array(
			'bcc',
			'cc',
			'delivered-to',
			'precedence',
			'reply-to',
			'return-path',
			'subject',
			'to',
		);
		foreach ( $headers_to_remove as $header_to_remove )
			$headers = $this->remove_header( $headers, $header_to_remove );
		
		$headers = trim( $headers );
		
		if ( $settings->reply_to != '' )
		{
			$reply_to_name = $settings->list_name;
			$reply_to_email = $settings->reply_to;
		}
		else
		{
			$from = $this->assemble_address( $options->headerinfo->from );
			$reply_to_name = $from;
			$reply_to_email = $from;
		}
		
		// Our message is just bulk.
		$headers = "Precedence: bulk\n" . $headers;
		// Add our reply to.
		$headers = "Reply-To: " . $reply_to_name . " <". $reply_to_email .">\n" . $headers;
		
		$mail_data = array(
			'body' => $options->body,
			'from' => $this->assemble_address( $options->headerinfo->from ),
			'headers' => $headers,
			'subject' => $subject,
			'reply_to' => array( $reply_to_email => $reply_to_name ),
		);
		
		// Create the actual mail data
		$mail_data_id = $this->sql_add_mail_data( array(
			'mail_data' => $mail_data,
		) );
		
		$readers = array_filter( explode("\n", $settings->readers) );
		
		// Calculate the priority.
		$priority = 100;	// Base value is 100.
		$priority += $settings->priority_modifier;
		$priority_decrease_per = $this->get_site_option( 'priority_decrease_per' );
		if ( $priority_decrease_per > 0 )
			$priority -= ( count( $readers ) / $priority_decrease_per );
		$priority = max( 0, $priority );	// 0 is the lowest priority. God help those batches that get this far down...
		
		foreach($readers as $reader)
		{
			$reader = trim( $reader );
			if ( !is_email($reader) )
			{
				$this->log( 'queue', $this->_( '<em>%s</em> is not a valid email address in <em>%s</em>.', $reader, $settings->list_name ) );
				continue;
			}

			$this->log( 'queue', $this->_( '<em>%s</em> added to mail queue for <em>%s</em>.', $reader, $settings->list_name ) );
			$this->sql_add_to_queue(array(
				'mail_data_id' => $mail_data_id,
				'priority' => $priority,
				'to' => $reader,
			));
		}
	}
	
	protected function message_is_code( $options )
	{
		$settings = $options->settings;		// Conv
		$subject = $options->headerinfo->subject;
		$subject = iconv_mime_decode( $subject, 0, 'UTF-8' );
		$code = preg_replace( '/.* /', '', $subject );
		
		if ( $options->verbose )
			$this->message( $this->_('Is <em>%s</em> a code?', htmlspecialchars( $subject ) ) );
		
		if ( strlen($code) != 32+5 )
			return false;
			
		if ( strpos( $code, '3wper' ) !== 0 )
			return false;
		
		$code = substr( $code, 5 );
		
		$from = $this->assemble_address( $options->headerinfo->from );
		$to = $this->assemble_address( $options->headerinfo->to );
		
		// Is there a message with this code?
		$message = $this->sql_get_code( $code );
		if ( $message == false )
		{
			if ( $options->verbose )
				$this->message( $this->_( 'Invalid code from <em>%s</em>: %s', $to, $code ) );
			
			// Should we tell the user that an invalid code was sent?
			if ( $settings->invalid_code_action == 'ignore_with_confirmation' )
			{
				$invalid_options = new stdClass();
				$invalid_options->to = $from;			// We're sending the message to the person who sent us the message, not to the list.
				$invalid_options->settings = $settings;
				$invalid_options->code = $code;
				$this->process_invalid_code_message( $invalid_options );
				
				$this->log( 'code', $this->_( 'Invalid code from <em>%s</em> for <em>%s</em>. Code: <em>%s</em>' , $from, $settings->list_name, $code ) );
			}
			return true;			// This is a code, but its invalid.
		}
		
		$this->log( 'code', $this->_( 'Valid code from <em>%s</em> for <em>%s</em>. Code: <em>%s</em>', $from, $settings->list_name, $code ) );
		
		if ( $options->verbose )
			$this->message( $this->_('Code is valid.') );
		
		$accept_options = new stdClass();
		$accept_options->from = $from;
		$this->accept_code( $code, $accept_options );
		$this->sql_delete_code( $code );
		
		return true;			// This WAS a code.
	}
	
	protected function process_queue()
	{
		$batch_connections = $this->get_site_option( 'send_batch_connections' );
		$batch_size = $this->get_site_option( 'send_batch_size' );

		$mails = $this->sql_queue_list(array(
			'limit' => $batch_size,
		));
		
		while ( count( $mails ) > 0 )
		{
			$curl_master = array();
			$curl_jobs = array();
			
			for( $batch_counter = 0; $batch_counter < $batch_connections; $batch_counter ++ )
			{
				if ( count( $mails ) < 1 )
					continue;
				
				$mail = array_pop( $mails );

				// Prepare a curl job for this list.
				$url = get_bloginfo( 'url' );
				$url = add_query_arg( array(
					'threewp_email_reflector' => true,
					'message_id' => $mail['message_id'],
					'nonce' => substr( $this->hash( $mail['message_id'] . NONCE_SALT ), 0, 12),
				), $url );
				$curl = $this->curl_init( $url );
				$curl_jobs[] = $curl;
			}

			if ( count($curl_jobs) > 0 )
			{
				$this->log( 'curl', $this->_( 'Starting %s sending children.', count($curl_jobs) ) );
				$mh = curl_multi_init();
				
				foreach( $curl_jobs as $curl )
					curl_multi_add_handle( $mh, $curl );

				$running = null;

				do
				{
					curl_multi_exec($mh, $running);
					usleep( 25000 );
				}
				while( $running );
				
				foreach( $curl_jobs as $curl )
					curl_multi_remove_handle($mh, $curl);
				curl_multi_close( $mh );
			}
		}	// while ( count( $mails ) > 0 )
	}
	
	protected function assemble_address( $array)
	{
		$array = $array[0];
		return $array->mailbox . '@' . $array->host;
	}
	
	protected function assemble_name($array)
	{
		$array = $array[0];
		if ( !isset($array->personal) )
			return $array->mailbox;
		else
			return $array->personal;
	}
	
	/**
		@brief		Logs a message.
		
		@param		$type
						Arbitrary log type string. Note that the type must be activated in the global settings.
		
		@param		$message
						Message string.
	**/
	public function log($type, $message)
	{
		do_action('threewp_activity_monitor_new_activity', array(
			'activity_id' => 'threewp_email_reflector_' . $type,
			'activity_strings' => array(
				"" => $message,
			),
		));
	}
	
	protected function imap_connect( $settings )
	{
		$connect_string = '{' . $settings->server_hostname . ':' . $settings->server_port;
		if ( $settings->server_ssl )
			$connect_string .= '/ssl';
		if ( $settings->server_novalidate_cert )
			$connect_string .= '/novalidate-cert';
		if ( $settings->server_other_options )
			$connect_string .= $settings->server_other_options;
		$connect_string .= '}';
		$stream = @imap_open(
			$connect_string,
			$settings->server_username,
			$settings->server_password,
			null,
			0
		);
		return $stream;
	}
	
	protected function delete_list( $list_id )
	{
		$this->sql_list_delete( $list_id );
	}
	
	/**
		Cleans up old queues and such.
	**/
	protected function clean_old()
	{
		$days = $this->get_site_option( 'old_age' );
		$this->sql_queue_clean( $days );
		$this->sql_clean_codes( $days );
		$this->sql_clean_queue_mail_data();
		$this->log( 'cron', $this->_( 'Queues cleaned up.' ) );
	}
	
	protected function clean_email_textarea( $textarea )
	{
		$textarea = strtolower( $textarea );
		$textarea = str_replace( " ", "\n", $textarea );
		$textarea = str_replace( ",", "\n", $textarea );
		$textarea = str_replace( "\r", "", $textarea );
		$textarea = explode( "\n", $textarea );
		$textarea = array_flip( $textarea );
		unset ( $textarea[''] );
		ksort( $textarea );
		$textarea = array_flip( $textarea );
		$textarea = implode( "\n", $textarea );
		return $textarea;
	}
	
	/**
		@brief		Searches $string for the $email address.
		
		Each line of the $string is analyzed individually.
		
		@param		string		$string		String to search
		@param		string		$email		E-mail address to find
		
		@return		boolean		True, if $string contains $email.
	**/
	protected function contains_email( $string, $email )
	{
		if ( strlen( trim( $string ) ) == '' )
			return false;

		$email = strtolower( $email );
		$string = strtolower( $string );
		
		// Break the string into an array
		$emails = explode( "\n", $string );
		$emails = array_flip ( $emails );
		
		return isset( $emails[$email] );
	}
	
	/**
		Initializes a curl slave.
		
		@param		$url		URL to fetch.
		@return					A curl object.
	**/
	protected function curl_init( $url )
	{
		$curl = curl_init();
        curl_setopt( $curl, CURLOPT_URL, $url ); // set url to post to
        curl_setopt( $curl, CURLOPT_FAILONERROR, 1 );
        curl_setopt( $curl, CURLOPT_FOLLOWLOCATION, $this->get_site_option( 'curl_follow_location' ) );
        curl_setopt( $curl, CURLOPT_RETURNTRANSFER, 1 );
        curl_setopt( $curl, CURLOPT_ENCODING, "UTF-8" );
        curl_setopt( $curl, CURLOPT_TIMEOUT, 20 );      // 20 second timeout.
        curl_setopt( $curl, CURLOPT_SSL_VERIFYPEER, false );	// Allow self-signed SSL
        curl_setopt( $curl, CURLOPT_SSL_VERIFYHOST, false );	// Allow self-signed SSL
        curl_setopt( $curl, CURLOPT_NOBODY, true );
        return $curl;
	}
	
	/**
		Returns a string of all the writers in these settings.
	**/
	protected function all_writers( $settings )
	{
		$rv = '';
		foreach( array( 'writers', 'writers_auth', 'writers_nomod', 'moderators' ) as $type )
			if ( $settings->$type != '' )
				$rv .= "\n" . $settings->$type;
		return trim( $rv );
	}
	
	/**
		Returns whether a user has a type of access to a list.
		
		$access_type can be either a string or an array of strings.
	**/
	protected function has_access( $user_id, $access_type, $settings )
	{
		if ( user_can( $user_id, 'manage_options' ) )
			return true;
		
		$key = "user_access_${user_id}";
		if ( ! isset( $settings->$key ) )
			return false;
		
		$user_access = $settings->$key;
		
		if ( ! is_array($access_type) )
			$access_type = array( $access_type );
		
		foreach( $access_type as $type )
			if ( strpos( $user_access, ' ' . $type . ' ' ) !== false )
				return true;
		
		return false;
	}
	
	protected function addressed_to_list( $options )
	{
		$to_types = array('to', 'cc', 'bcc');
		$tos = array();
		
		$headerinfo = $options->headerinfo;
		$settings = $options->settings;
		
		// Collect an array of email addresses that this mail has been addressed to.
		foreach( $to_types as $to_type )
			if ( isset( $headerinfo->$to_type ) )
				foreach( $headerinfo->$to_type as $address )
					$tos[] = $this->assemble_address( array($address) );
		
		$accept_tos = $settings->accept_tos();
		
		return count( array_intersect( $accept_tos, $tos ) ) > 0;
	}
	
	/**
		@brief		Removes a header from a message's headers.
		
		@param		string		$headers				The headers as taken from the IMAP message.
		@param		string		$header_to_remove		The header to remove. "Subject" or "To" or whatever.
		
		@return		string		The headers without that specific header.
	**/
	public function remove_header( $headers, $header_to_remove )
	{
		$rv = array();
		
		$header_to_find = $this->strtolower( $header_to_remove ) . ':';
		
		$just_skipped = false;	// If we've just skipped a header, we need to continue ignoring lines until char 0 is not a space or tab.
		$headers = str_replace( "\r", "", $headers );
		$lines = array_filter( explode( "\n", $headers ) );

		foreach ( $lines as $line )
		{
			if ( $just_skipped )
			{
				if ( strlen( ltrim( $line ) ) != strlen( $line ) )
					continue;
				else
					$just_skipped = false;
			}
			
			if ( strpos( $this->strtolower($line), $header_to_find ) === 0 )
			{
				$just_skipped = true;
			}
			else
				$rv[] = $line;
		}
		return implode( "\n", $rv );
	}
	
	/**
		Sends a message from the queue.
		
		@param		$message_id		Message ID to send.
		@return		True if message was sent.
	**/
	public function send_message( $message_id )
	{
		$message = $this->sql_get_message( $message_id );
		if ( $message === false )
			return false;

		$this->load_language();

		$mail_data = $this->sql_decode( $message['mail_data'] );
		$mail_data['to'] = $message['to'];
		$to = $mail_data['to'];	// Conv.
		
		$headers = $mail_data['headers'];
		
		/**
			This is interesting.
			
			Here I would like to have replaced all \n with \r\n, as per RFC 2822.
			
			Unfortunately this causes Gmail to insert _extra_ newlines after each line. No idea why.
			
			Best sending is done by just using \n, even though it breaks the RFC. *sigh*.
		**/
//		$headers = str_replace( "\n", "\r\n", $headers );

		$options = '';
		
		// Add the sender for the return-path.
		$options .= '-f' . $mail_data['from'];
		
		$result = mail( $to, $mail_data['subject'], $mail_data['body'], $headers, $options );
		
		if ( $result )
		{
			$this->log( 'mail', $this->_( 'Message sent to <em>%s</em>.', $to ) );
			$this->log( 'queue', $this->_( 'Message %s to <em>%s</em> removed from queue.', $message_id, $to ) );
			$this->sql_unqueue( $message_id );
		}
		else
		{
			if ( $message['failures'] == 2 )
			{
				$this->log( 'mail', $this->_( 'Message %s to <em>%s</em> has failed too many times and been removed from the queue.', $message_id, $to ) );
				$this->log( 'queue', $this->_( 'Message %s to <em>%s</em> removed from queue because of too many failures.', $message_id, $to ) );
				$this->sql_unqueue( $message_id );
			}
			else
			{
				$this->sql_queue_fail( $message_id );
				$this->log( 'mail', $this->_( 'Message %s to <em>%s</em> could not be sent.', $message_id, $to ) );
			}
			return false;
		}	// if ($result === true)
		return true;
	}
	
	/**
		Describes (show columns) a table.
		
		Inserts the prefix automatically.
		
		@param	$table_name		The name of the table (without the db_ prefix).
		@return					An array of table columns, with the Field column as the key.
	**/
	protected function sql_describe( $table_name )
	{
		$fields = $this->query( 'SHOW COLUMNS FROM `'.$this->wpdb->base_prefix.$table_name.'`' );
		$fields = $this->array_rekey( $fields, 'Field' );
		return $fields;
	}
	
	// --------------------------------------------------------------------------------------------
	// ----------------------------------------- SQL
	// --------------------------------------------------------------------------------------------

	protected function sql_list_get( $list_id )
	{
		return $this->query_single("SELECT * FROM `".$this->wpdb->base_prefix."3wp_email_reflector_lists` WHERE list_id = '$list_id'");
	}
	
	protected function sql_list_add()
	{
		return $this->query_insert_id("INSERT INTO `".$this->wpdb->base_prefix."3wp_email_reflector_lists` (datetime_created) VALUES ('".$this->now()."')");
	}
	
	protected function sql_list_delete( $list_id )
	{
		$this->query("DELETE FROM `".$this->wpdb->base_prefix."3wp_email_reflector_lists` WHERE list_id = '$list_id'");
		$this->query("DELETE FROM `".$this->wpdb->base_prefix."3wp_email_reflector_list_settings` WHERE list_id = '$list_id'");
	}
	
	protected function sql_get_setting($list_id, $key, $default_value = false)
	{
		$value = $this->query("SELECT value FROM `".$this->wpdb->base_prefix."3wp_email_reflector_list_settings` WHERE list_id = '$list_id' AND `key` = '$key'");
		if ( count($value) < 1 )
			return $default_value;
		else
			return $value[0]['value'];
	}
	
	protected function sql_update_setting( $list_id, $key, $value )
	{
		if ( $this->sql_get_setting( $list_id, $key ) === false )
			$this->query("INSERT INTO `".$this->wpdb->base_prefix."3wp_email_reflector_list_settings` (`list_id`, `key`, `value`) VALUE ('$list_id', '$key', '$value')");
		else
			$this->query("UPDATE `".$this->wpdb->base_prefix."3wp_email_reflector_list_settings` SET
				`value` = '$value'
				WHERE list_id = '$list_id' AND `key` = '$key'");
	}
	
	protected function sql_access_settings_save( $list_id, $user_access_settings )
	{
		// Delete all the current user access settings.
		$this->query("DELETE FROM `".$this->wpdb->base_prefix."3wp_email_reflector_list_settings` WHERE list_id = '$list_id' AND `key` LIKE 'user_access_%'");
		
		if ( count( $user_access_settings ) < 1 )
			return;
		
		$values = array();
		foreach( $user_access_settings as $user_id => $access )
		{
			$access = implode( ' ' , array_keys($access) );
			$values[] = "'$list_id', 'user_access_${user_id}', ' $access '";
		}

		$this->query("INSERT INTO `".$this->wpdb->base_prefix."3wp_email_reflector_list_settings` (`list_id`, `key`, `value`) VALUE (".implode(" ), (", $values).")");
	}
	
	protected function sql_user_access_settings_save( $user_id, $list_access_settings )
	{
		// Delete all access for this user.
		$this->query("DELETE FROM `".$this->wpdb->base_prefix."3wp_email_reflector_list_settings` WHERE `key` LIKE 'user_access_".$user_id."'");

		if ( count( $list_access_settings ) < 1 )
			return;

		$values = array();
		foreach( $list_access_settings as $list_id => $access )
		{
			$access = implode( ' ' , array_keys($access) );
			if ( trim($access) == '' )
				continue;
			$values[] = "'$list_id', 'user_access_${user_id}', ' $access '";
		}

		$this->query("INSERT INTO `".$this->wpdb->base_prefix."3wp_email_reflector_list_settings` (`list_id`, `key`, `value`) VALUE (".implode(" ), (", $values).")");
	}

	// Queue mail data
	protected function sql_add_mail_data( $options )
	{
		$options['mail_data_short'] = $options['mail_data'];
		unset( $options['mail_data_short']['body'] );
		$options['mail_data_short'] = $this->sql_encode( $options['mail_data_short'] );
		$options['mail_data'] = $this->sql_encode( $options['mail_data'] );

		return $this->query_insert_id("INSERT INTO `".$this->wpdb->base_prefix."3wp_email_reflector_queue_mail_data` (`mail_data_short`, `mail_data`)
			VALUE (
				'".$options['mail_data_short']."',
				'".$options['mail_data']."'
			)");
	}

	protected function sql_clean_queue_mail_data()
	{
		$this->query("DELETE FROM `".$this->wpdb->base_prefix."3wp_email_reflector_queue_mail_data` WHERE `mail_data_id` NOT IN
			( SELECT DISTINCT `mail_data_id` FROM `".$this->wpdb->base_prefix."3wp_email_reflector_queue` )
		");
			
	}
	
	//	QUEUE	
	protected function sql_add_to_queue( $options )
	{
		$options = array_merge( array(
			'priority' => 100,
		), $options );
		$this->query("INSERT INTO `".$this->wpdb->base_prefix."3wp_email_reflector_queue` (`datetime_added`, `mail_data_id`, `priority`, `to`)
			VALUE (
				'".$this->now()."',
				'".$options['mail_data_id']."',
				'".$options['priority']."',
				'".$options['to']."'
			)");
	}
	
	protected function sql_queue_list($options)
	{
		$options = array_merge(array(
			'limit' => 50,
			'mail_data' => false,
			'page' => 1,
		), $options);
	
		$SELECT = array(
			'datetime_added',
			'failures',
			'mail_data_short',
			'message_id',
			'priority',
			'to',
		);
		
		$LIMIT = '';
		if ( $options['limit'] !== null )
			$LIMIT = 'LIMIT ' . ( ($options['page'] -1 ) * $options['limit'] ) . ',' . $options['limit'];
		
		if ( $options['mail_data'] === true )
			$SELECT[] = 'mail_data';
		
		return $this->query( "SELECT `".implode('`,`', $SELECT)."` FROM `".$this->wpdb->base_prefix."3wp_email_reflector_queue`
			INNER JOIN `".$this->wpdb->base_prefix."3wp_email_reflector_queue_mail_data` USING (mail_data_id)
			ORDER BY priority DESC, failures, message_id $LIMIT" );
	}
	
	/**
		Returns a solitary message.
		
		@param		$message_id		Message ID to retrieve from the queue.
		@return						The whole message ID row, or false if the specified ID doesn't exist.
	**/
	protected function sql_get_message( $message_id )
	{
		return $this->query_single("SELECT * FROM `".$this->wpdb->base_prefix."3wp_email_reflector_queue`
			INNER JOIN `".$this->wpdb->base_prefix."3wp_email_reflector_queue_mail_data` USING (mail_data_id)
			WHERE message_id = '$message_id'");
	}
	
	protected function sql_queue_fail( $message_id )
	{
		return $this->query("UPDATE `".$this->wpdb->base_prefix."3wp_email_reflector_queue` SET failures = failures + 1 WHERE message_id = '$message_id'");
	}
	
	protected function sql_unqueue( $message_id )
	{
		return $this->query("DELETE FROM `".$this->wpdb->base_prefix."3wp_email_reflector_queue` WHERE message_id = '$message_id'");
	}
	
	protected function sql_queue_clean( $days = 7 )
	{
		$query = "DELETE FROM `".$this->wpdb->base_prefix."3wp_email_reflector_queue` WHERE datetime_added < now() - interval $days day";
		$this->query($query);
	}
	
	/**
		CODES
		-----
	**/
	protected function sql_delete_code( $code )
	{
		$query = "DELETE FROM `".$this->wpdb->base_prefix."3wp_email_reflector_codes` WHERE `auth_code` = '" . $code . "'";
		$this->query( $query );
	}
	
	protected function sql_get_code( $code )
	{
		return $this->query_single("SELECT * FROM `".$this->wpdb->base_prefix."3wp_email_reflector_codes` WHERE auth_code = '$code'");
	}

	protected function sql_add_code( $code, $message_data )
	{
		$message_data = $this->sql_encode( $message_data );
		return $this->query_insert_id("INSERT INTO `".$this->wpdb->base_prefix."3wp_email_reflector_codes` (`auth_code`, `datetime_added`, `message_data`) VALUES
			('$code', '".$this->now()."' , '$message_data')");
	}
	
	protected function sql_clean_codes( $days = 7 )
	{
		$query = "DELETE FROM `".$this->wpdb->base_prefix."3wp_email_reflector_codes` WHERE datetime_added < now() - interval $days day";
		$this->query( $query );
	}
	
	protected function sql_count_codes()
	{
		$result = $this->query("SELECT COUNT(*) AS count FROM `".$this->wpdb->base_prefix."3wp_email_reflector_codes`");
		return $result[0]['count'];
	}

	protected function sql_list_codes( $options )
	{
		$options = array_merge(array(
			'limit' => 50,
			'page' => 1,
		), $options);
	
		$LIMIT = '';
		if ( $options['limit'] !== null )
			$LIMIT = 'LIMIT ' . (($options['page'] -1 )* $options['limit']) . ',' . $options['limit'];
		
		return $this->query("SELECT * FROM `".$this->wpdb->base_prefix."3wp_email_reflector_codes` ORDER BY auth_code $LIMIT");
	}
}

$threewp_email_reflector = new ThreeWP_Email_Reflector();

