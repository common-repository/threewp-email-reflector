<?php

class ThreeWP_Email_Reflector_Settings
{
	public $accept_to = false;
	public $administrative_reply_to = '';
	public $always_auth = false;
	public $cron_minutes = 5;
	public $enabled = false;
	public $invalid_code_action = 'ignore';
	public $list_name = false;
	public $moderators = '';
	public $non_writer_action = '';
	public $priority_modifier = 0;
	public $readers = '';
	public $reply_to = '';
	public $server_hostname = 'imap.example.com';
	public $server_port = '993';
	public $server_ssl = true;
	public $server_novalidate_cert = true;
	public $server_other_options = '';
	public $stats_last_collected = '';
	public $stats_mails_ignored = 0;
	public $stats_mails_reflected = 0;
	public $subject_line_prefix = '';
	public $subject_line_suffix = '';
	public $valid_code_action = 'accept';
	public $writers = '';
	public $writers_auth = '';
	public $writers_nomod = '';
	
	public function __construct()
	{
		global $threewp_email_reflector;
		
		$this->list_name = $threewp_email_reflector->_( 'List created at %s', $threewp_email_reflector->now() );
	}

	/**
		@return		The administrative address, if any. If none is set, returns the list's address.
	**/
	public function administrative_address()
	{
		return $this->administrative_reply_to != '' ? $this->administrative_reply_to : $this->reply_to;
	}
	
	/**
		@return		An array of all the email addresses this list accepts to.
	**/
	public function accept_tos()
	{
		$rv = array();

		$list_tos = array('reply_to', 'administrative_reply_to', 'accept_to');
		foreach( $list_tos as $list_to )
		{
			$addresses = explode( "\n", $this->$list_to );
			$rv = array_merge( $rv, $addresses );
		}
		$rv = array_filter( $rv );
		return $rv;
	}
	
	/**
		@brief		Is this list enabled?
		
		@return		boolean		True if the list is enabled. Else false.
	**/
	public function enabled()
	{
		return $this->enabled;
	}
}

