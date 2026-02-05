<?php
if ( ! defined('WP_UNINSTALL_PLUGIN') ) { exit; }
delete_option('wprl_setup_complete');
delete_option('wprl_org_type');
delete_option('wprl_business_name');
delete_option('wprl_website_name');
delete_option('wprl_report_email');
delete_option('wprl_weekly_reports_enabled');
