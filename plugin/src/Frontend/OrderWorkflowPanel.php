<?php

declare(strict_types=1);

namespace Zarbalman\Core\Frontend;

use WC_Order;
use Zarbalman\Core\Domain\Order\WeightValue;
use Zarbalman\Core\Domain\Order\WeightUnitPolicy;
use Zarbalman\Core\Domain\Workflow\Actor;
use Zarbalman\Core\Domain\Workflow\AuthorizationPolicy;
use Zarbalman\Core\Domain\Workflow\WorkflowDefinition;
use Zarbalman\Core\Infrastructure\WorkflowStore;
use Zarbalman\Core\Infrastructure\PurchaseRequestRepository;
use Zarbalman\Core\Presentation\StateLabels;

final class OrderWorkflowPanel {
	public function __construct(
		private readonly WorkflowStore $store,
		private readonly AuthorizationPolicy $authorization,
		private readonly WeightUnitPolicy $weight_unit,
		private readonly PurchaseRequestRepository $requests
	) {}

	public function register(): void {
		add_action( 'woocommerce_order_details_after_order_table', array( $this, 'render_for_buyer' ), 20 );
		add_action( 'dokan_order_detail_after_order_items', array( $this, 'render_for_vendor' ), 20 );
		add_shortcode( 'zbm_order_workflow', array( $this, 'shortcode' ) );
	}

	public function render_for_buyer( WC_Order $order ): void {
		$this->render( $order, Actor::BUYER );
	}

	public function render_for_vendor( WC_Order $order ): void {
		$this->render( $order, Actor::VENDOR );
	}

	public function shortcode( array|string $attributes ): string {
		$attributes = is_array( $attributes ) ? $attributes : array();
		$order_id = absint( $attributes['order_id'] ?? $_GET['order_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing value.
		$order    = wc_get_order( $order_id );
		if ( ! $order ) {
			return '';
		}
		$actor = $this->authorization->actor_type_for( $order, get_current_user_id() );
		if ( null === $actor ) {
			return '';
		}
		ob_start();
		$this->render( $order, $actor );
		return (string) ob_get_clean();
	}

	private function render( WC_Order $order, string $expected_actor ): void {
		if (
			'yes' === $order->get_meta( '_zbm_managed_order', true )
			|| 'zarbalman-core-spec3' === $order->get_created_via()
			|| null !== $this->requests->findByOrderId( $order->get_id() )
		) {
			return;
		}
		$actor = $this->authorization->actor_type_for( $order, get_current_user_id() );
		if ( $expected_actor !== $actor ) {
			return;
		}
		$snapshot = $this->store->snapshot( $order->get_id() );
		?>
		<section class="zbm-order-workflow" aria-labelledby="zbm-workflow-title-<?php echo esc_attr( (string) $order->get_id() ); ?>">
			<h2 id="zbm-workflow-title-<?php echo esc_attr( (string) $order->get_id() ); ?>"><?php echo esc_html__( 'پیگیری و تأیید سفارش', 'zarbalman-core' ); ?></h2>
			<p><strong><?php echo esc_html__( 'مرحله فعلی:', 'zarbalman-core' ); ?></strong> <?php echo esc_html( StateLabels::get( $snapshot['state'] ) ); ?></p>
			<?php $this->render_notice(); ?>
			<?php if ( ! $this->weight_unit->is_satisfied() ) : ?>
				<p class="woocommerce-error"><?php echo esc_html( $this->weight_unit->error()->get_error_message() ); ?></p>
			<?php endif; ?>
			<?php if ( $snapshot['is_paused'] ) : ?>
				<p class="woocommerce-info"><?php echo esc_html__( 'این سفارش برای بررسی مدیریت متوقف شده است.', 'zarbalman-core' ); ?></p>
			<?php else : ?>
				<?php $this->render_deadline( $snapshot, $actor ); ?>
				<?php $this->render_actions( $order, $snapshot, $actor ); ?>
			<?php endif; ?>
		</section>
		<?php
	}

	/** @param array<string,mixed> $snapshot */
	private function render_deadline( array $snapshot, string $actor ): void {
		$deadline = Actor::BUYER === $actor ? $snapshot['buyer_deadline_gmt'] : $snapshot['vendor_deadline_gmt'];
		if ( ! is_string( $deadline ) || '' === $deadline ) {
			return;
		}
		$local = get_date_from_gmt( $deadline, 'Y/m/d H:i' );
		echo '<p><strong>' . esc_html__( 'مهلت پاسخ:', 'zarbalman-core' ) . '</strong> ' . esc_html( $local ) . '</p>';
	}

	/** @param array<string,mixed> $snapshot */
	private function render_actions( WC_Order $order, array $snapshot, string $actor ): void {
		$state = $snapshot['state'];
		if ( Actor::BUYER === $actor && WorkflowDefinition::DELIVERED === $state ) {
			$this->weight_form( $order, WorkflowDefinition::BUYER_WEIGHT_RECORDED, __( 'ثبت وزن واقعی و تأیید', 'zarbalman-core' ), $snapshot );
			$this->reason_form( $order, WorkflowDefinition::DISPUTE_OPEN, __( 'ثبت اعتراض', 'zarbalman-core' ), true );
			return;
		}
		if ( Actor::BUYER === $actor && WorkflowDefinition::DEPOSIT_PENDING === $state ) {
			$this->reason_form( $order, WorkflowDefinition::CANCELLATION_REQUESTED, __( 'درخواست لغو سفارش', 'zarbalman-core' ), false );
			return;
		}
		if ( Actor::VENDOR !== $actor ) {
			return;
		}
		if ( WorkflowDefinition::SUPPLIER_REVIEW === $state ) {
			$this->simple_form( $order, WorkflowDefinition::PREPARING, __( 'تأیید سفارش و شروع آماده‌سازی', 'zarbalman-core' ) );
			$this->reason_form( $order, WorkflowDefinition::CANCELLATION_REQUESTED, __( 'درخواست لغو', 'zarbalman-core' ), false );
		} elseif ( WorkflowDefinition::PREPARING === $state ) {
			$this->weight_form( $order, WorkflowDefinition::IN_TRANSIT, __( 'ثبت وزن خروجی و ارسال بار', 'zarbalman-core' ) );
			$this->reason_form( $order, WorkflowDefinition::CANCELLATION_REQUESTED, __( 'درخواست لغو', 'zarbalman-core' ), false );
		} elseif ( WorkflowDefinition::IN_TRANSIT === $state ) {
			$this->simple_form( $order, WorkflowDefinition::DELIVERED, __( 'اعلام تحویل به خریدار', 'zarbalman-core' ) );
		} elseif ( WorkflowDefinition::BUYER_WEIGHT_RECORDED === $state ) {
			$this->render_weights( $order, $snapshot );
			$this->simple_form( $order, WorkflowDefinition::SELLER_WEIGHT_CONFIRMED, __( 'تأیید وزن خریدار', 'zarbalman-core' ) );
			$this->reason_form( $order, WorkflowDefinition::WEIGHT_DISPUTED, __( 'اعتراض به وزن', 'zarbalman-core' ), true );
		}
	}

	private function simple_form( WC_Order $order, string $to_state, string $label ): void {
		$this->form_open( $order, $to_state );
		$this->button( $label, 'button alt' );
		echo '</form>';
	}

	private function reason_form( WC_Order $order, string $to_state, string $label, bool $evidence ): void {
		$this->form_open( $order, $to_state, $evidence );
		echo '<p><label>' . esc_html__( 'دلیل و توضیحات', 'zarbalman-core' ) . '<br><textarea name="reason" required minlength="10" maxlength="1000" rows="3"></textarea></label></p>';
		if ( $evidence ) {
			echo '<p><label>' . esc_html__( 'تصاویر باسکول یا محصول (حداکثر ۴ تصویر، هرکدام ۵ مگابایت)', 'zarbalman-core' );
			echo '<br><input type="file" name="zbm_evidence[]" accept="image/jpeg,image/png,image/webp" multiple required></label></p>';
		}
		$this->button( $label, 'button' );
		echo '</form>';
	}

	/** @param array<string,mixed>|null $snapshot */
	private function weight_form( WC_Order $order, string $to_state, string $label, ?array $snapshot = null ): void {
		if ( ! $this->weight_unit->is_satisfied() ) {
			return;
		}

		$this->form_open( $order, $to_state );
		foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
			echo '<p><strong>' . esc_html( $item->get_name() ) . '</strong><br>';
			echo esc_html( sprintf( __( 'تعداد سفارش: %s', 'zarbalman-core' ), wc_format_decimal( $item->get_quantity(), 0 ) ) );

			$declared_weight = $this->declared_package_weight( $item );
			if ( null !== $declared_weight ) {
				echo '<br>' . esc_html(
					sprintf(
						__( 'وزن اعلامی هر واحد: %1$s کیلوگرم — وزن تقریبی کل: %2$s کیلوگرم', 'zarbalman-core' ),
						WeightValue::format_tenths( $declared_weight['per_unit_tenths'] ),
						WeightValue::format_tenths( $declared_weight['total_tenths'] )
					)
				);
			}

			echo '<br><label>' . esc_html__( 'وزن کل واقعی این قلم (کیلوگرم)', 'zarbalman-core' );
			echo '<br><input type="text" inputmode="decimal" pattern="[0-9۰-۹٠-٩]+([.,٫][0-9۰-۹٠-٩])?" name="weights[' . esc_attr( (string) $item_id ) . ']" required aria-label="' . esc_attr__( 'وزن کل واقعی این قلم به کیلوگرم', 'zarbalman-core' ) . '"></label></p>';
		}
		echo '<p><label>' . esc_html__( 'توضیحات (اختیاری)', 'zarbalman-core' ) . '<br><textarea name="note" maxlength="1000" rows="2"></textarea></label></p>';
		$this->button( $label, 'button alt' );
		echo '</form>';
	}

	/**
	 * Returns a non-financial package-weight reference when a product has an
	 * exact one-decimal WooCommerce physical weight and a whole-line quantity.
	 * Settlement always uses the separately recorded actual weight.
	 *
	 * @return array{per_unit_tenths:int,total_tenths:int}|null
	 */
	private function declared_package_weight( \WC_Order_Item_Product $item ): ?array {
		if ( ! $this->weight_unit->is_satisfied() ) {
			return null;
		}

		$product = $item->get_product();
		if ( ! $product instanceof \WC_Product ) {
			return null;
		}

		$per_unit_tenths = WeightValue::parse_tenths( $product->get_weight() );
		if ( is_wp_error( $per_unit_tenths ) ) {
			return null;
		}

		$quantity = (string) $item->get_quantity();
		if ( 1 !== preg_match( '/^[1-9][0-9]*$/', $quantity ) ) {
			return null;
		}

		$units = (int) $quantity;
		if ( $per_unit_tenths > intdiv( PHP_INT_MAX, $units ) ) {
			return null;
		}

		return array(
			'per_unit_tenths' => $per_unit_tenths,
			'total_tenths'    => $per_unit_tenths * $units,
		);
	}

	/** @param array<string,mixed> $snapshot */
	private function render_weights( WC_Order $order, array $snapshot ): void {
		$by_item = array();
		foreach ( $snapshot['weights'] as $row ) {
			$by_item[ $row['order_item_id'] ] = $row;
		}
		echo '<ul>';
		foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
			$tenths = $by_item[ $item_id ]['buyer_weight_tenths'] ?? null;
			echo '<li>' . esc_html( $item->get_name() ) . ': ';
			echo null === $tenths ? '—' : esc_html( WeightValue::format_tenths( (int) $tenths ) . ' kg' );
			echo '</li>';
		}
		echo '</ul>';
	}

	private function form_open( WC_Order $order, string $to_state, bool $multipart = false ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"' . ( $multipart ? ' enctype="multipart/form-data"' : '' ) . '>';
		echo '<input type="hidden" name="action" value="zbm_core_transition">';
		echo '<input type="hidden" name="order_id" value="' . esc_attr( (string) $order->get_id() ) . '">';
		echo '<input type="hidden" name="to_state" value="' . esc_attr( $to_state ) . '">';
		echo '<input type="hidden" name="idempotency_key" value="' . esc_attr( 'form-' . wp_generate_uuid4() ) . '">';
		echo '<input type="hidden" name="correlation_id" value="' . esc_attr( wp_generate_uuid4() ) . '">';
		wp_nonce_field( 'zbm_core_order_' . $order->get_id() );
	}

	private function render_notice(): void {
		$result = isset( $_GET['zbm_result'] ) ? sanitize_key( wp_unslash( $_GET['zbm_result'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only status key.
		if ( '' === $result ) {
			return;
		}
		if ( 'success' === $result ) {
			echo '<p class="woocommerce-message">' . esc_html__( 'عملیات با موفقیت ثبت شد.', 'zarbalman-core' ) . '</p>';
			return;
		}
		echo '<p class="woocommerce-error">' . esc_html__( 'عملیات ثبت نشد. مرحله سفارش و اطلاعات واردشده را بررسی کنید.', 'zarbalman-core' ) . '</p>';
	}

	private function button( string $label, string $class ): void {
		echo '<button type="submit" class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</button>';
	}
}
