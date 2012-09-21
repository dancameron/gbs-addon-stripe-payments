<?php
/*
Plugin Name: Group Buying Payment Processor - Stripe Payments
Version: Beta 1
Plugin URI: http://sproutventure.com/wordpress/group-buying
Description: Stripe Direct Payments Add-on.
Author: Sprout Venture
Author URI: http://sproutventure.com/wordpress
Plugin Author: Dan Cameron
Contributors: Dan Cameron
Text Domain: group-buying
Domain Path: /lang
*/

add_action('gb_register_processors', 'gb_load_stripe');

function gb_load_stripe() {
	require_once('groupBuyingStripe.class.php');
}