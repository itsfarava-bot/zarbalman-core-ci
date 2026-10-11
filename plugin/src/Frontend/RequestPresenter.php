<?php

declare(strict_types=1);

namespace Zarbalman\Core\Frontend;

use InvalidArgumentException;
use Zarbalman\Core\Domain\PurchaseRequest\DeliveryMethod;
use Zarbalman\Core\Domain\PurchaseRequest\PurchaseRequest;
use Zarbalman\Core\Domain\PurchaseRequest\RequestState;
use Zarbalman\Core\Support\JalaliDate;
use Zarbalman\Core\Infrastructure\ManagedCaseRepository;

final class RequestPresenter {
	public function __construct( private readonly ?ManagedCaseRepository $cases = null ) {}
	/** @return array<string,string|int|bool|null> */
	public function present( PurchaseRequest $request, bool $include_prices = true ): array {
		$product = wc_get_product( $request->variationId() > 0 ? $request->variationId() : $request->productId() );
		$deposit_deadline = $request->depositDeadlineGmt();
		$deposit_deadline_ready = RequestState::DEPOSIT_PENDING === $request->state()
			&& $request->paymentReady()
			&& ! $request->isPaused()
			&& is_string( $deposit_deadline )
			&& '' !== trim( $deposit_deadline );
		$deadline = RequestState::SUPPLIER_REVIEW === $request->state()
			? $request->supplierDeadlineGmt()
			: ( $deposit_deadline_ready ? $deposit_deadline : null );
		$deadline_notice = null;
		if ( RequestState::SUPPLIER_ACCEPTED === $request->state() || ( RequestState::DEPOSIT_PENDING === $request->state() && ! $request->paymentReady() ) ) {
			$deadline_notice = __( 'مهلت پرداخت پس از آماده‌شدن اطلاعات پرداخت آغاز می‌شود و در همین صفحه نمایش داده خواهد شد.', 'zarbalman-core' );
		} elseif ( RequestState::DEPOSIT_PENDING === $request->state() && $request->isPaused() ) {
			$deadline_notice = __( 'زمان پرداخت توسط مدیریت متوقف شده است و پس از ادامهٔ پرونده دوباره نمایش داده می‌شود.', 'zarbalman-core' );
		} elseif ( RequestState::DEPOSIT_PENDING === $request->state() && $request->paymentReady() && ! $deposit_deadline_ready ) {
			$deadline_notice = __( 'مهلت پرداخت هنوز برای این درخواست در دسترس نیست؛ لطفاً صفحه را تازه کنید.', 'zarbalman-core' );
		}
		$is_test = $request->isTest();
		$state_label = $is_test
			? (
				\Zarbalman\Core\Domain\PurchaseRequest\ExecutionMode::TEST_PENDING === $request->executionMode()
					? __( 'در حال بازیابی تأیید آزمایشی مدیریت', 'zarbalman-core' )
					: __( 'تأیید آزمایشی مدیریت — هیچ وجهی دریافت نشده', 'zarbalman-core' )
			)
			: $this->stateLabel( $request->state() );
		$display_state = $request->state();
		if ( RequestState::DEPOSIT_PAID === $request->state() && null !== $this->cases ) {
			$status = $this->cases->displayStatus( $request );
			if ( is_array( $status ) && 'awaiting_deposit' !== $status['state'] ) {
				$display_state = $status['state'];
				$state_label = $this->stateLabel( $display_state );
				if ( $is_test ) { $state_label .= ' — ' . __( 'پروندهٔ آزمایشی', 'zarbalman-core' ); }
				$deadline = $request->isPaused() ? null : $status['deadline_gmt'];
			}
		}

		$view = array(
			'id'                    => $request->id(),
			'public_id'             => $request->publicId(), // Internal compatibility value; never render this as the user reference.
			'request_number'        => $request->requestNumber(),
			'product_name'          => $product ? $product->get_name() : __( 'محصول حذف‌شده', 'zarbalman-core' ),
			'product_url'           => get_permalink( $request->productId() ) ?: '',
			'product_image'         => $product ? $product->get_image( 'woocommerce_thumbnail' ) : '',
			'quantity'              => $request->quantity(),
			'package_weight'        => $this->weight( $request->nominalPackageWeightTenths() ),
			'reference_weight'      => $this->weight( $request->referenceWeightTenths() ),
			'delivery_method'       => DeliveryMethod::PICKUP_FROM_VENDOR === $request->deliveryMethod()
				? __( 'دریافت حضوری از فروشنده', 'zarbalman-core' )
				: __( 'تحویل در نشانی خریدار', 'zarbalman-core' ),
			'delivery_window'       => $this->window( $request->deliveryStartGmt(), $request->deliveryEndGmt(), $request->deliveryWindowType() ),
			'state'                 => $request->state(),
			'state_label'           => $state_label,
			'state_tone'            => $is_test ? 'test' : $this->stateTone( $display_state ),
			'operation_state'       => $display_state,
			'execution_mode'        => $request->executionMode(),
			'is_test'               => $is_test,
			'deadline_gmt'          => $deadline,
			'deadline_iso'          => $deadline ? str_replace( ' ', 'T', $deadline ) . 'Z' : null,
			'deadline_label'        => $deadline ? $this->jalaliGmt( $deadline, true ) : null,
			'deadline_notice'       => $deadline_notice,
			'is_paused'             => $request->isPaused(),
			'rejection_reason'      => $request->rejectionReason(),
		);
		if ( $include_prices ) {
			$view += array(
				'package_price' => $this->money( $request->packagePriceToman() ),
				'estimated_total' => $this->money( $request->estimatedTotalToman() ),
				'pricing_version' => $request->pricingVersion(),
				'estimated_shipping' => $this->money( $request->estimatedShippingToman() ),
				'estimated_gross' => $this->money( $request->estimatedGrossToman() ),
				'deposit' => $this->money( $request->depositToman() ),
			);
		}
		$view['lines'] = array();
		if ( $request->isMultiline() ) {
			foreach ( $request->lines() as $key => $line ) {
				$row = array( 'id' => $key, 'name' => $line['name'], 'quantity' => $line['quantity'], 'package_weight' => $this->weight( $line['nominal_weight_tenths'] ), 'reference_weight' => $this->weight( $line['reference_tenths'] ) );
				if ( $include_prices ) { $row['package_price'] = $this->money( $line['package_price_toman'] ); $row['estimated_total'] = $this->money( $line['estimated_total'] ); }
				$view['lines'][] = $row;
			}
			$first = reset( $view['lines'] );
			$view['product_name'] = $first['name'] . sprintf( __( ' و %d محصول دیگر', 'zarbalman-core' ), count( $view['lines'] ) - 1 );
			$view['package_weight'] = __( 'مطابق ردیف محصولات', 'zarbalman-core' );
			if ( $include_prices ) { $view['package_price'] = __( 'مطابق ردیف محصولات', 'zarbalman-core' ); }
		}
		return $view;
	}

	public function stateLabel( string $state ): string {
		$labels = array(
			'preparing' => __( 'در حال آماده‌سازی سفارش', 'zarbalman-core' ),
			'ready_for_delivery' => __( 'آمادهٔ ارسال', 'zarbalman-core' ),
			'ready_for_pickup' => __( 'آمادهٔ دریافت حضوری', 'zarbalman-core' ),
			'in_transit' => __( 'در مسیر تحویل', 'zarbalman-core' ),
			'buyer_review' => __( 'در انتظار وزن و عکس خریدار', 'zarbalman-core' ),
			'seller_review' => __( 'در انتظار تأیید وزن فروشنده', 'zarbalman-core' ),
			'final_weight_locked' => __( 'وزن نهایی ثبت شد', 'zarbalman-core' ),
			'balance_due' => __( 'در انتظار پرداخت مانده', 'zarbalman-core' ),
			'completed' => __( 'سفارش تکمیل شد', 'zarbalman-core' ),
			'return_review' => __( 'در بررسی بازگشت یا لغو', 'zarbalman-core' ),
			RequestState::SUPPLIER_REVIEW           => __( 'در انتظار پاسخ فروشنده', 'zarbalman-core' ),
			RequestState::SUPPLIER_ACCEPTED         => __( 'پذیرفته‌شده؛ در انتظار آماده‌شدن پرداخت', 'zarbalman-core' ),
			RequestState::SUPPLIER_REJECTED         => __( 'ردشده توسط فروشنده', 'zarbalman-core' ),
			RequestState::SUPPLIER_RESPONSE_EXPIRED => __( 'مهلت فروشنده پایان یافت', 'zarbalman-core' ),
			RequestState::DEPOSIT_PENDING           => __( 'در انتظار پیش‌پرداخت ۱۰٪', 'zarbalman-core' ),
			RequestState::DEPOSIT_PAID              => __( 'پیش‌پرداخت موفق', 'zarbalman-core' ),
			RequestState::DEPOSIT_EXPIRED           => __( 'مهلت پیش‌پرداخت پایان یافت', 'zarbalman-core' ),
			RequestState::LATE_PAYMENT_REVIEW       => __( 'پرداخت در بررسی مدیریت', 'zarbalman-core' ),
			RequestState::ADMIN_REVIEW              => __( 'در بررسی مدیریت', 'zarbalman-core' ),
			RequestState::CANCELLED                 => __( 'لغوشده', 'zarbalman-core' ),
		);
		return $labels[ $state ] ?? __( 'وضعیت نامشخص', 'zarbalman-core' );
	}

	/** @param array<string,mixed> $delivery */
	public function estimatedGrandTotal( PurchaseRequest $request, array $delivery ): ?string {
		if ( 2 === $request->pricingVersion() ) {
			return $this->money( $request->estimatedGrossToman() );
		}
		if ( DeliveryMethod::DELIVERY_TO_BUYER_ADDRESS !== $request->deliveryMethod() ) {
			return null;
		}
		$shipping = $delivery['estimated_shipping_toman'] ?? null;
		$product  = $request->estimatedTotalToman();
		if ( ! is_int( $shipping ) || $shipping < 0 || $product < 0 || $product > PHP_INT_MAX - $shipping ) {
			return null;
		}

		return $this->money( $product + $shipping );
	}

	private function stateTone( string $state ): string {
		return match ( $state ) {
			RequestState::DEPOSIT_PAID, 'completed' => 'success',
			RequestState::SUPPLIER_REJECTED, RequestState::SUPPLIER_RESPONSE_EXPIRED,
			RequestState::DEPOSIT_EXPIRED, RequestState::CANCELLED => 'danger',
			RequestState::LATE_PAYMENT_REVIEW, RequestState::ADMIN_REVIEW => 'warning',
			default => 'info',
		};
	}

	private function money( int $amount ): string {
		return number_format_i18n( $amount, 0 ) . ' ' . __( 'تومان', 'zarbalman-core' );
	}

	private function weight( int $tenths ): string {
		$whole = intdiv( $tenths, 10 );
		$fraction = $tenths % 10;
		return number_format_i18n( $whole, 0 ) . ( $fraction > 0 ? '٫' . $fraction : '' ) . ' ' . __( 'کیلوگرم', 'zarbalman-core' );
	}

	private function window( string $start_gmt, string $end_gmt, string $type ): string {
		$include_time = 'exact' === $type;
		return $this->jalaliGmt( $start_gmt, $include_time ) . ' — ' . $this->jalaliGmt( $end_gmt, $include_time );
	}

	private function jalaliGmt( string $gmt, bool $include_time ): string {
		try {
			return JalaliDate::formatGmt( $gmt, wp_timezone(), $include_time );
		} catch ( InvalidArgumentException ) {
			return '—';
		}
	}
}
