<?php
defined( 'ABSPATH' ) || exit;

/**
 * Tabele wtyczki: zamówienia i zdarzenia (wejścia na strony, rozpoczęte płatności).
 */
class Lejek_DB {

	const VERSION = '1';

	public static function orders() {
		global $wpdb;
		return $wpdb->prefix . 'lejek_orders';
	}

	public static function events() {
		global $wpdb;
		return $wpdb->prefix . 'lejek_events';
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$orders  = self::orders();
		$events  = self::events();

		dbDelta(
			"CREATE TABLE {$orders} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  mode varchar(4) NOT NULL DEFAULT 'test',
  status varchar(20) NOT NULL DEFAULT 'new',
  product_id bigint(20) unsigned NOT NULL DEFAULT 0,
  product_name varchar(190) NOT NULL DEFAULT '',
  slot_month varchar(7) NOT NULL DEFAULT '',
  amount int(11) NOT NULL DEFAULT 0,
  currency varchar(3) NOT NULL DEFAULT 'usd',
  email varchar(190) NOT NULL DEFAULT '',
  name varchar(190) NOT NULL DEFAULT '',
  phone varchar(40) NOT NULL DEFAULT '',
  country varchar(2) NOT NULL DEFAULT '',
  utm_source varchar(100) NOT NULL DEFAULT '',
  utm_medium varchar(100) NOT NULL DEFAULT '',
  utm_campaign varchar(100) NOT NULL DEFAULT '',
  visitor varchar(32) NOT NULL DEFAULT '',
  stripe_session varchar(100) NOT NULL DEFAULT '',
  stripe_payment_intent varchar(100) NOT NULL DEFAULT '',
  stripe_customer varchar(100) NOT NULL DEFAULT '',
  note text NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY stripe_session (stripe_session),
  KEY created_at (created_at),
  KEY product_slot (product_id,slot_month),
  KEY payment_intent (stripe_payment_intent)
) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$events} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  created_at datetime NOT NULL,
  type varchar(20) NOT NULL DEFAULT 'view',
  page_id bigint(20) unsigned NOT NULL DEFAULT 0,
  product_id bigint(20) unsigned NOT NULL DEFAULT 0,
  visitor varchar(32) NOT NULL DEFAULT '',
  utm_source varchar(100) NOT NULL DEFAULT '',
  utm_campaign varchar(100) NOT NULL DEFAULT '',
  referrer varchar(100) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  KEY type_date (type,created_at),
  KEY visitor (visitor)
) {$charset};"
		);

		update_option( 'lejek_db_version', self::VERSION, false );
	}

	public static function maybe_upgrade() {
		if ( get_option( 'lejek_db_version' ) !== self::VERSION ) {
			self::install();
		}
	}
}
