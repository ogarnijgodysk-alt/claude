<?php
defined( 'ABSPATH' ) || exit;

/**
 * Szyfruje klucze Stripe zapisywane w bazie danych.
 * Klucz szyfrujący pochodzi z soli w wp-config.php, więc sam zrzut bazy nie wystarczy, żeby je odczytać.
 */
class Lejek_Crypto {

	private static function key() {
		return hash( 'sha256', wp_salt( 'auth' ) . '|lejek', true );
	}

	public static function encrypt( $plain ) {
		$plain = (string) $plain;
		if ( '' === $plain ) {
			return '';
		}
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			return 'b64:' . base64_encode( $plain );
		}
		$iv  = random_bytes( 16 );
		$enc = openssl_encrypt( $plain, 'aes-256-cbc', self::key(), OPENSSL_RAW_DATA, $iv );
		$mac = hash_hmac( 'sha256', $iv . $enc, self::key(), true );
		return 'v1:' . base64_encode( $iv . $mac . $enc );
	}

	public static function decrypt( $stored ) {
		$stored = (string) $stored;
		if ( '' === $stored ) {
			return '';
		}
		if ( 0 === strpos( $stored, 'b64:' ) ) {
			return (string) base64_decode( substr( $stored, 4 ) );
		}
		if ( 0 !== strpos( $stored, 'v1:' ) || ! function_exists( 'openssl_decrypt' ) ) {
			return '';
		}
		$raw = base64_decode( substr( $stored, 3 ), true );
		if ( false === $raw || strlen( $raw ) < 49 ) {
			return '';
		}
		$iv  = substr( $raw, 0, 16 );
		$mac = substr( $raw, 16, 32 );
		$enc = substr( $raw, 48 );
		if ( ! hash_equals( hash_hmac( 'sha256', $iv . $enc, self::key(), true ), $mac ) ) {
			return '';
		}
		$plain = openssl_decrypt( $enc, 'aes-256-cbc', self::key(), OPENSSL_RAW_DATA, $iv );
		return false === $plain ? '' : $plain;
	}
}
