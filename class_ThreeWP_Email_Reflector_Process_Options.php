<?php

class ThreeWP_Email_Reflector_Process_Options
{
	/**
		ID of list we are processing.
		@var	$list_id
	**/
	public $list_id;
	
	/**
		ThreeWP_Email_Reflector_Settings class.
		@var	$settings
	**/
	public $settings;

	/**
		IMAP stream.
		@var	$stream
	**/
	public $stream;
	
	/**
		Are we processing this message verbosely?
		@var	$verbose
	**/
	public $verbose = false;
}

