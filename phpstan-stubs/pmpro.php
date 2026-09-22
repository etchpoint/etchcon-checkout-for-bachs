<?php

class PMProGateway {
	/** @var string|null */
	public $gateway;
	/** @var string */
	public $gateway_environment;

	/** @param string|null $gateway */
	public function __construct( $gateway = null ) {}

	/** @param string $feature @return bool|string */
	public static function supports( $feature ) { return false; }

	/** @param MemberOrder $order @return bool */
	public function process( &$order ) { return false; }
}

class MemberOrder {
	/** @var int */
	public $id = 0;
	/** @var int */
	public $user_id = 0;
	/** @var int */
	public $membership_id = 0;
	/** @var object|null */
	public $membership_level;
	/** @var string|float|int */
	public $total = '0';
	/** @var string */
	public $status = '';
	/** @var string */
	public $gateway = '';
	/** @var string */
	public $gateway_environment = '';
	/** @var string */
	public $payment_transaction_id = '';
	/** @var string */
	public $error = '';
	/** @var string */
	public $shorterror = '';

	/** @param int|null $id */
	public function __construct( $id = null ) {}

	/** @return bool */
	public function saveOrder() { return true; }
}

/** @param object $level */
function pmpro_isLevelRecurring( &$level ): bool { return false; }

function pmpro_getGateway(): string { return ''; }

/** @param MemberOrder $order */
function pmpro_save_checkout_data_to_order( $order ): void {}

/** @param string|null $page @param string $querystring @param string|null $scheme */
function pmpro_url( $page = null, $querystring = '', $scheme = null ): string { return ''; }

/** @param int $order_id @param string $meta_key @param mixed $meta_value @param mixed $prev_value */
function update_pmpro_membership_order_meta( $order_id, $meta_key, $meta_value, $prev_value = '' ): bool { return true; }

/** @param int $order_id @param string $key @param bool $single @return mixed */
function get_pmpro_membership_order_meta( $order_id, $key = '', $single = false ) { return ''; }

/** @param MemberOrder $order */
function pmpro_pull_checkout_data_from_order( $order ): void {}

/** @param MemberOrder $order */
function pmpro_complete_async_checkout( $order ): bool { return true; }
