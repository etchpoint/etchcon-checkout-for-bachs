<?php

class GFForms {
	public static function include_payment_addon_framework(): void {}
}

class GFAddOn {
	/** @param class-string $class_name */
	public static function register( $class_name ): void {}
}

class GFPaymentAddOn extends GFAddOn {
	/** @var string */
	protected $_version = '';
	/** @var string */
	protected $_min_gravityforms_version = '';
	/** @var string */
	protected $_slug = '';
	/** @var string */
	protected $_path = '';
	/** @var string */
	protected $_full_path = '';
	/** @var string */
	protected $_title = '';
	/** @var string */
	protected $_short_title = '';
	/** @var bool */
	protected $_supports_callbacks = false;
	/** @var bool */
	protected $_requires_credit_card = false;

	public function init(): void {}

	/** @param array<int, mixed> $options */
	public function add_delayed_payment_support( $options ): void {}

	/** @return array<int, array<string, mixed>> */
	public function feed_settings_fields(): array { return array(); }

	/**
	 * @param array<string, mixed> $entry
	 * @param array<string, mixed> $action
	 */
	public function complete_payment( $entry, $action ): void {}
}

class GFCommon {
	public static function get_currency(): string { return 'USD'; }
}

class GFAPI {
	/** @return array<string, mixed>|WP_Error */
	public static function get_entry( $entry_id ) { return array(); }
}

/** @return mixed */
function gform_get_meta( $entry_id, $meta_key ) { return false; }

/** @return int|bool */
function gform_update_meta( $entry_id, $meta_key, $meta_value, $form_id = null ) { return 1; }
