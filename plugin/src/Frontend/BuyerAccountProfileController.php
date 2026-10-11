<?php

declare(strict_types=1);

namespace Zarbalman\Core\Frontend;

use WP_Error;
use WP_User;
use Zarbalman\Core\Support\IranianMobile;

/**
 * Replaces the customer-facing identity fields on the WooCommerce account
 * screen while keeping WordPress' required internal email column untouched.
 * The visible fields are validated and persisted server-side; CSS/JavaScript
 * are only responsible for presentation and hiding the native duplicate rows.
 */
final class BuyerAccountProfileController {
	public function register(): void {
		add_action( 'woocommerce_edit_account_form_start', array( $this, 'renderFields' ) );
		add_action( 'woocommerce_save_account_details_errors', array( $this, 'validate' ), 10, 2 );
		add_action( 'woocommerce_save_account_details', array( $this, 'save' ), 10, 1 );
		add_filter( 'body_class', array( $this, 'bodyClass' ) );
	}

	public function renderFields(): void {
		if ( ! is_user_logged_in() ) {
			return;
		}

		$user = wp_get_current_user();
		$full_name = $this->hasPostedValue( 'zbm_account_full_name' )
			? $this->postedFullName()
			: $this->currentFullName( $user );
		$phone = $this->hasPostedValue( 'zbm_account_phone' )
			? $this->normalizePhone( $this->postedValue( 'zbm_account_phone' ) )
			: $this->currentPhone( $user->ID );
		?>
		<section class="zbm-account-profile-card" aria-labelledby="zbm-account-profile-title">
			<div class="zbm-account-profile-heading">
				<span class="zbm-account-profile-kicker"><?php esc_html_e( 'اطلاعات تماس', 'zarbalman-core' ); ?></span>
				<h2 id="zbm-account-profile-title"><?php esc_html_e( 'مشخصات حساب کاربری', 'zarbalman-core' ); ?></h2>
				<p><?php esc_html_e( 'نام و شماره تلفن شما برای پیگیری درخواست‌ها و هماهنگی‌های سایت استفاده می‌شود.', 'zarbalman-core' ); ?></p>
			</div>
			<div class="zbm-account-profile-fields">
				<p class="zbm-account-profile-field zbm-account-profile-field-full">
					<label for="zbm-account-full-name"><?php esc_html_e( 'نام و نام خانوادگی', 'zarbalman-core' ); ?> <span class="required" aria-hidden="true">*</span></label>
					<input id="zbm-account-full-name" type="text" name="zbm_account_full_name" value="<?php echo esc_attr( $full_name ); ?>" autocomplete="name" maxlength="120" required aria-required="true">
				</p>
				<p class="zbm-account-profile-field zbm-account-profile-field-full">
					<label for="zbm-account-phone"><?php esc_html_e( 'شماره تلفن همراه', 'zarbalman-core' ); ?> <span class="required" aria-hidden="true">*</span></label>
					<input id="zbm-account-phone" type="tel" name="zbm_account_phone" value="<?php echo esc_attr( $phone ); ?>" inputmode="tel" autocomplete="tel" maxlength="13" required aria-required="true" placeholder="۰۹۱۲۳۴۵۶۷۸۹">
					<small><?php esc_html_e( 'شماره را به‌صورت ۱۱ رقمی و با ۰۹ وارد کنید.', 'zarbalman-core' ); ?></small>
				</p>
			</div>
		</section>
		<?php
	}

	public function validate( WP_Error $errors, object $user ): void {
		$full_name = $this->postedFullName();
		$phone     = $this->normalizePhone( $this->postedValue( 'zbm_account_phone' ) );
		$parts     = $this->nameParts( $full_name );

		if ( ! $this->hasValidFullName( $full_name ) ) {
			$errors->add( 'zbm_account_full_name', __( 'لطفاً نام و نام خانوادگی را وارد کنید.', 'zarbalman-core' ) );
		} else {
			// WooCommerce passes a mutable stdClass here.  Populate its native
			// identity fields before core saves it, so its billing-name sync also
			// receives the new single-field value.
			$user->first_name   = $parts[0];
			$user->last_name    = implode( ' ', array_slice( $parts, 1 ) );
			$user->display_name = $full_name;
		}

		if ( ! preg_match( '/\A09[0-9]{9}\z/', $phone ) ) {
			$errors->add( 'zbm_account_phone', __( 'شماره تلفن همراه باید ۱۱ رقم و با ۰۹ شروع شود.', 'zarbalman-core' ) );
		}

		// The visible account UI deliberately has no editable email field.
		// Preserve the stored email on the object passed by reference so a
		// forged but valid account_email POST value cannot replace it.
		if ( isset( $user->ID ) ) {
			$current_user = get_userdata( (int) $user->ID );
			if ( $current_user instanceof WP_User ) {
				$user->user_email = $current_user->user_email;
			}
		}
	}

	public function save( int $user_id ): void {
		$full_name = $this->postedFullName();
		$phone     = $this->normalizePhone( $this->postedValue( 'zbm_account_phone' ) );
		$parts     = $this->nameParts( $full_name );

		// WooCommerce stops before this hook when validation fails.  Keep this
		// guard as a second line of defence for custom form integrations.
		if ( ! $this->hasValidFullName( $full_name ) || ! preg_match( '/\A09[0-9]{9}\z/', $phone ) ) {
			return;
		}

		$first_name = array_shift( $parts );
		$last_name  = implode( ' ', $parts );
		$updated = wp_update_user(
			array(
				'ID'           => $user_id,
				'first_name'   => $first_name,
				'last_name'    => $last_name,
				'display_name' => $full_name,
			)
		);
		if ( is_wp_error( $updated ) ) {
			if ( function_exists( 'wc_add_notice' ) ) {
				wc_add_notice( $updated->get_error_message(), 'error' );
			}
			return;
		}

		update_user_meta( $user_id, 'billing_phone', $phone );
		update_user_meta( $user_id, 'phone', $phone );
		update_user_meta( $user_id, 'billing_first_name', $first_name );
		update_user_meta( $user_id, 'billing_last_name', $last_name );

		// Keep the WooCommerce customer object in sync for checkout, billing
		// forms and integrations that read WC_Customer instead of user meta.
		if ( class_exists( 'WC_Customer' ) ) {
			try {
				$customer = new \WC_Customer( $user_id );
				if ( is_callable( array( $customer, 'set_billing_first_name' ) ) ) {
					$customer->set_billing_first_name( $first_name );
				}
				if ( is_callable( array( $customer, 'set_billing_last_name' ) ) ) {
					$customer->set_billing_last_name( $last_name );
				}
				if ( is_callable( array( $customer, 'set_billing_phone' ) ) ) {
					$customer->set_billing_phone( $phone );
				}
				if ( is_callable( array( $customer, 'save' ) ) ) {
					$customer->save();
				}
			} catch ( \Throwable $exception ) {
				// User meta above remains the durable fallback if an optional
				// integration customer object cannot be persisted.
			}
		}
	}

	/** @param list<string> $classes */
	public function bodyClass( array $classes ): array {
		if ( $this->isEditAccountPage() ) {
			$classes[] = 'zbm-account-edit';
		}
		return $classes;
	}

	private function isEditAccountPage(): bool {
		return function_exists( 'is_account_page' )
			&& is_account_page()
			&& function_exists( 'is_wc_endpoint_url' )
			&& is_wc_endpoint_url( 'edit-account' );
	}

	private function postedValue( string $key ): string {
		if ( ! isset( $_POST[ $key ] ) || ! is_scalar( $_POST[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the account form nonce before invoking its save hooks.
			return '';
		}
		return sanitize_text_field( wp_unslash( (string) $_POST[ $key ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- See the guard above.
	}

	private function hasPostedValue( string $key ): bool {
		return isset( $_POST[ $key ] ) && is_scalar( $_POST[ $key ] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the account form nonce before invoking its save hooks.
	}

	/**
	 * The site intentionally presents one identity field.  A single-token
	 * display name is valid; WordPress/WooCommerce can keep its last-name
	 * column empty until the customer provides a second token.
	 */
	private function hasValidFullName( string $full_name ): bool {
		return '' !== trim( $full_name );
	}

	private function postedFullName(): string {
		$value = trim( str_replace( "\xC2\xA0", ' ', $this->postedValue( 'zbm_account_full_name' ) ) );
		$normalized = preg_replace( '/[\s\x{200C}]+/u', ' ', $value );
		return is_string( $normalized ) ? trim( $normalized ) : $value;
	}

	/** @return list<string> */
	private function nameParts( string $full_name ): array {
		$parts = preg_split( '/[\s\x{200C}]+/u', trim( $full_name ), -1, PREG_SPLIT_NO_EMPTY );
		return is_array( $parts ) ? array_values( array_filter( array_map( 'trim', $parts ), static fn ( string $part ): bool => '' !== $part ) ) : array();
	}

	private function normalizePhone( string $value ): string {
		return IranianMobile::normalize( $value );
	}

	private function currentFullName( WP_User $user ): string {
		$full_name = trim( $user->first_name . ' ' . $user->last_name );
		return '' !== $full_name ? $full_name : (string) $user->display_name;
	}

	private function currentPhone( int $user_id ): string {
		// Editable identity is not a notification destination: do not apply
		// zbm_core_user_mobile here or persist an SMS override on a later save.
		foreach ( array( 'billing_phone', 'phone', 'mobile', 'phone_number' ) as $key ) {
			$phone = get_user_meta( $user_id, $key, true );
			if ( IranianMobile::isValid( $phone ) ) {
				return IranianMobile::normalize( $phone );
			}
		}
		$profile = get_user_meta( $user_id, 'dokan_profile_settings', true );
		$phone = is_array( $profile ) ? ( $profile['phone'] ?? '' ) : '';
		return IranianMobile::isValid( $phone ) ? IranianMobile::normalize( $phone ) : '';
	}
}
