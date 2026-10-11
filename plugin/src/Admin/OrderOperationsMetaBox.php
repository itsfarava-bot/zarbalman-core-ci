<?php

declare(strict_types=1);

namespace Zarbalman\Core\Admin;

use Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController;
use WC_Order;
use WP_Post;
use Zarbalman\Core\Domain\Order\WeightValue;
use Zarbalman\Core\Domain\Order\WeightUnitPolicy;
use Zarbalman\Core\Domain\Workflow\StateMachine;
use Zarbalman\Core\Domain\Workflow\WorkflowDefinition;
use Zarbalman\Core\Infrastructure\AuditLogRepository;
use Zarbalman\Core\Infrastructure\WorkflowStore;
use Zarbalman\Core\Infrastructure\PurchaseRequestRepository;
use Zarbalman\Core\Http\EvidenceDownloadController;
use Zarbalman\Core\Presentation\StateLabels;
use Zarbalman\Core\Security\AdminCapabilities;

final class OrderOperationsMetaBox {
	public function __construct(
		private readonly WorkflowStore $store,
		private readonly StateMachine $machine,
		private readonly AuditLogRepository $audit,
		private readonly WeightUnitPolicy $weight_unit,
		private readonly EvidenceDownloadController $evidence_download,
		private readonly PurchaseRequestRepository $requests
	) {}

	public function register(): void {
		add_action( 'add_meta_boxes', array( $this, 'add' ) );
	}

	public function add(): void {
		$screen = 'shop_order';
		if ( class_exists( CustomOrdersTableController::class ) && function_exists( 'wc_get_container' ) && function_exists( 'wc_get_page_screen_id' ) ) {
			$controller = wc_get_container()->get( CustomOrdersTableController::class );
			if ( $controller->custom_orders_table_usage_is_enabled() ) {
				$screen = wc_get_page_screen_id( 'shop-order' );
			}
		}

		add_meta_box(
			'zbm-order-operations',
			__( 'کنترل امن گردش زربال‌من', 'zarbalman-core' ),
			array( $this, 'render' ),
			$screen,
			'normal',
			'high'
		);
	}

	public function render( WP_Post|WC_Order $object ): void {
		if ( ! AdminCapabilities::currentUserCanOperate() ) {
			return;
		}

		$order = $object instanceof WC_Order ? $object : wc_get_order( $object->ID );
		if ( ! $order ) {
			return;
		}
		if (
			'yes' === $order->get_meta( '_zbm_managed_order', true )
			|| 'zarbalman-core-spec3' === $order->get_created_via()
			|| null !== $this->requests->findByOrderId( $order->get_id() )
		) {
			echo '<p>' . esc_html__( 'این سفارش متعلق به گردش مستقل درخواست زربال‌من است. کنترل آن فقط از صفحه «درخواست‌های زربال‌من» انجام می‌شود.', 'zarbalman-core' ) . '</p>';
			echo '<p><a class="button" href="' . esc_url( admin_url( 'admin.php?page=zbm-core-requests' ) ) . '">' . esc_html__( 'بازکردن درخواست‌های زربال‌من', 'zarbalman-core' ) . '</a></p>';
			return;
		}
		$snapshot = $this->store->snapshot( $order->get_id() );
		?>
		<p><strong><?php echo esc_html__( 'مرحله داخلی:', 'zarbalman-core' ); ?></strong> <?php echo esc_html( StateLabels::get( $snapshot['state'] ) ); ?></p>
		<p><strong><?php echo esc_html__( 'نسخه گردش:', 'zarbalman-core' ); ?></strong> <?php echo esc_html( (string) $snapshot['version'] ); ?></p>
		<?php if ( $snapshot['is_paused'] ) : ?>
			<div class="notice notice-warning inline"><p><?php echo esc_html__( 'سفارش متوقف است.', 'zarbalman-core' ); ?> <?php echo esc_html( $snapshot['pause_reason'] ); ?></p></div>
		<?php endif; ?>
		<?php $this->deadline_row( __( 'مهلت خریدار', 'zarbalman-core' ), $snapshot['buyer_deadline_gmt'] ); ?>
		<?php $this->deadline_row( __( 'مهلت تأمین‌کننده', 'zarbalman-core' ), $snapshot['vendor_deadline_gmt'] ); ?>
		<?php if ( ! $this->weight_unit->is_satisfied() ) : ?>
			<div class="notice notice-error inline"><p><?php echo esc_html( $this->weight_unit->error()->get_error_message() ); ?></p></div>
		<?php endif; ?>

		<?php $this->render_weights( $order, $snapshot ); ?>
		<?php $this->render_controls( $order, $snapshot ); ?>
		<?php $this->render_history( $order->get_id() ); ?>
		<?php
	}

	/** @param array<string,mixed> $snapshot */
	private function render_weights( WC_Order $order, array $snapshot ): void {
		$by_item = array();
		foreach ( $snapshot['weights'] as $row ) {
			$by_item[ $row['order_item_id'] ] = $row;
		}
		echo '<h4>' . esc_html__( 'وزن اقلام', 'zarbalman-core' ) . '</h4>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'قلم', 'zarbalman-core' ) . '</th><th>' . esc_html__( 'سفارش', 'zarbalman-core' ) . '</th><th>' . esc_html__( 'تأمین‌کننده', 'zarbalman-core' ) . '</th><th>' . esc_html__( 'خریدار', 'zarbalman-core' ) . '</th><th>' . esc_html__( 'نهایی', 'zarbalman-core' ) . '</th></tr></thead><tbody>';
		foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
			$row = $by_item[ $item_id ] ?? array();
			echo '<tr><td>' . esc_html( $item->get_name() ) . '</td><td>' . esc_html( wc_format_decimal( $item->get_quantity(), 1 ) ) . '</td>';
			echo '<td>' . esc_html( $this->weight( $row['vendor_weight_tenths'] ?? null ) ) . '</td>';
			echo '<td>' . esc_html( $this->weight( $row['buyer_weight_tenths'] ?? null ) ) . '</td>';
			echo '<td>' . esc_html( $this->weight( $row['final_weight_tenths'] ?? null ) ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/** @param array<string,mixed> $snapshot */
	private function render_controls( WC_Order $order, array $snapshot ): void {
		echo '<h4>' . esc_html__( 'اقدام مدیریت', 'zarbalman-core' ) . '</h4>';
		$is_terminal = in_array( $snapshot['state'], array( WorkflowDefinition::COMPLETED, WorkflowDefinition::CANCELLED ), true );
		if ( ! $is_terminal ) {
			$this->control_form( $order, ! $snapshot['is_paused'] );
		}
		if ( $snapshot['is_paused'] ) {
			return;
		}
		if ( $is_terminal ) {
			echo '<p class="description">' . esc_html__( 'این سفارش به مرحله نهایی رسیده و اقدام عملیاتی دیگری مجاز نیست.', 'zarbalman-core' ) . '</p>';
			return;
		}

		$state = $snapshot['state'];
		if ( WorkflowDefinition::CREATED === $state ) {
			$this->transition_reason_form( $order, WorkflowDefinition::DEPOSIT_PENDING, __( 'آغاز فرایند پیش‌پرداخت', 'zarbalman-core' ) );
		} elseif ( WorkflowDefinition::DEPOSIT_PENDING === $state ) {
			$this->transition_reason_form( $order, WorkflowDefinition::CANCELLATION_REQUESTED, __( 'لغو قبل از تأیید پیش‌پرداخت', 'zarbalman-core' ) );
			echo '<p class="description">' . esc_html__( 'تأیید پرداخت فقط از مسیر امن مالی و با شناسه تراکنش انجام خواهد شد.', 'zarbalman-core' ) . '</p>';
		} elseif ( WorkflowDefinition::DEPOSIT_PAID === $state ) {
			$this->transition_reason_form( $order, WorkflowDefinition::SUPPLIER_REVIEW, __( 'ارسال سفارش پرداخت‌شده برای تأمین‌کننده', 'zarbalman-core' ) );
			$this->transition_reason_form( $order, WorkflowDefinition::CANCELLATION_REQUESTED, __( 'توقف و ارسال برای لغو', 'zarbalman-core' ) );
		} elseif ( WorkflowDefinition::SUPPLIER_REVIEW === $state ) {
			$this->transition_reason_form( $order, WorkflowDefinition::PREPARING, __( 'تأیید مدیریتی سفارش', 'zarbalman-core' ) );
			$this->transition_reason_form( $order, WorkflowDefinition::CANCELLATION_REQUESTED, __( 'ثبت درخواست لغو', 'zarbalman-core' ) );
		} elseif ( WorkflowDefinition::PREPARING === $state ) {
			$this->admin_weight_form( $order, WorkflowDefinition::IN_TRANSIT, __( 'ثبت وزن خروجی و ارسال توسط مدیریت', 'zarbalman-core' ) );
			$this->transition_reason_form( $order, WorkflowDefinition::CANCELLATION_REQUESTED, __( 'ثبت درخواست لغو', 'zarbalman-core' ) );
		} elseif ( WorkflowDefinition::IN_TRANSIT === $state ) {
			$this->transition_reason_form( $order, WorkflowDefinition::DELIVERED, __( 'اعلام تحویل توسط مدیریت', 'zarbalman-core' ) );
		} elseif ( WorkflowDefinition::DELIVERED === $state ) {
			$this->admin_weight_form( $order, WorkflowDefinition::BUYER_WEIGHT_RECORDED, __( 'ثبت وزن به‌جای خریدار', 'zarbalman-core' ) );
			$this->transition_reason_form( $order, WorkflowDefinition::ADMIN_REVIEW, __( 'ارسال مستقیم به بررسی مدیریت', 'zarbalman-core' ) );
		} elseif ( WorkflowDefinition::BUYER_WEIGHT_RECORDED === $state ) {
			$this->transition_reason_form( $order, WorkflowDefinition::SELLER_WEIGHT_CONFIRMED, __( 'تأیید وزن توسط مدیریت', 'zarbalman-core' ) );
			$this->transition_reason_form( $order, WorkflowDefinition::ADMIN_REVIEW, __( 'بازکردن بررسی مدیریت', 'zarbalman-core' ) );
		} elseif ( in_array( $state, array( WorkflowDefinition::WEIGHT_DISPUTED, WorkflowDefinition::DISPUTE_OPEN ), true ) ) {
			$this->transition_reason_form( $order, WorkflowDefinition::ADMIN_REVIEW, __( 'شروع رسیدگی مدیریت', 'zarbalman-core' ) );
		} elseif ( WorkflowDefinition::ADMIN_REVIEW === $state ) {
			$this->admin_weight_form( $order, WorkflowDefinition::SELLER_WEIGHT_CONFIRMED, __( 'حل اختلاف و ثبت وزن نهایی', 'zarbalman-core' ), true );
			$this->transition_reason_form( $order, WorkflowDefinition::CANCELLATION_REQUESTED, __( 'نتیجه رسیدگی: لغو', 'zarbalman-core' ) );
		} elseif ( WorkflowDefinition::CANCELLATION_REQUESTED === $state ) {
			$this->transition_reason_form( $order, WorkflowDefinition::CANCELLED, __( 'تأیید نهایی لغو', 'zarbalman-core' ) );
		} elseif ( WorkflowDefinition::BALANCE_PENDING === $state ) {
			$this->transition_reason_form( $order, WorkflowDefinition::DISPUTE_OPEN, __( 'توقف پرداخت و بازکردن اختلاف', 'zarbalman-core' ) );
			$this->transition_reason_form( $order, WorkflowDefinition::RETURN_COORDINATION, __( 'آغاز هماهنگی بازگشت کالا', 'zarbalman-core' ) );
			echo '<p class="description">' . esc_html__( 'ثبت مانده به‌عنوان پرداخت‌شده فقط از مسیر امن مالی مجاز است.', 'zarbalman-core' ) . '</p>';
		} elseif ( WorkflowDefinition::BALANCE_PAID === $state ) {
			$this->transition_reason_form( $order, WorkflowDefinition::ACCEPTANCE_WINDOW, __( 'آغاز مرحله نهایی پس از پرداخت کامل', 'zarbalman-core' ) );
		} elseif ( WorkflowDefinition::ACCEPTANCE_WINDOW === $state ) {
			$this->transition_reason_form( $order, WorkflowDefinition::COMPLETED, __( 'تکمیل نهایی سفارش', 'zarbalman-core' ) );
			$this->transition_reason_form( $order, WorkflowDefinition::DISPUTE_OPEN, __( 'بازکردن اختلاف پیش از تکمیل', 'zarbalman-core' ) );
		} elseif ( WorkflowDefinition::RETURN_COORDINATION === $state ) {
			$this->transition_reason_form( $order, WorkflowDefinition::CANCELLATION_REQUESTED, __( 'ارسال بازگشت کالا برای لغو نهایی', 'zarbalman-core' ) );
		} elseif ( WorkflowDefinition::SELLER_WEIGHT_CONFIRMED === $state ) {
			echo '<p class="description">' . esc_html__( 'وزن نهایی ثبت شده است. صدور لینک مانده و مهلت پرداخت در اسپک مالی انجام می‌شود.', 'zarbalman-core' ) . '</p>';
		}
	}

	private function control_form( WC_Order $order, bool $pause ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin:12px 0;padding:10px;border:1px solid #ccd0d4">';
		echo '<input type="hidden" name="action" value="zbm_core_control"><input type="hidden" name="order_id" value="' . esc_attr( (string) $order->get_id() ) . '">';
		echo '<input type="hidden" name="paused" value="' . ( $pause ? '1' : '0' ) . '"><input type="hidden" name="correlation_id" value="' . esc_attr( wp_generate_uuid4() ) . '">';
		wp_nonce_field( 'zbm_core_order_' . $order->get_id() );
		echo '<p><label>' . esc_html__( 'دلیل اجباری', 'zarbalman-core' ) . '<br><textarea name="reason" required minlength="10" maxlength="1000" rows="2" style="width:100%"></textarea></label></p>';
		submit_button( $pause ? __( 'توقف امن سفارش', 'zarbalman-core' ) : __( 'بازگشایی سفارش', 'zarbalman-core' ), $pause ? 'secondary' : 'primary', 'submit', false );
		echo '</form>';
	}

	private function transition_reason_form( WC_Order $order, string $to_state, string $label ): void {
		if ( ! $this->machine->can_transition( $this->store->current_state( $order->get_id() ), $to_state, 'admin' ) ) {
			return;
		}
		$this->transition_form_open( $order, $to_state );
		echo '<p><label>' . esc_html__( 'دلیل اجباری', 'zarbalman-core' ) . '<br><textarea name="reason" required minlength="10" maxlength="1000" rows="2" style="width:100%"></textarea></label></p>';
		submit_button( $label, 'secondary', 'submit', false );
		echo '</form>';
	}

	private function admin_weight_form( WC_Order $order, string $to_state, string $label, bool $prefill = false ): void {
		if ( ! $this->weight_unit->is_satisfied() ) {
			return;
		}
		if ( ! $this->machine->can_transition( $this->store->current_state( $order->get_id() ), $to_state, 'admin' ) ) {
			return;
		}
		$snapshot = $this->store->snapshot( $order->get_id() );
		$by_item  = array();
		foreach ( $snapshot['weights'] as $row ) {
			$by_item[ $row['order_item_id'] ] = $row;
		}
		$this->transition_form_open( $order, $to_state );
		foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
			$prefill_tenths = $by_item[ $item_id ]['final_weight_tenths']
				?? $by_item[ $item_id ]['buyer_weight_tenths']
				?? null;
			$value = $prefill && null !== $prefill_tenths
				? WeightValue::format_tenths( (int) $prefill_tenths )
				: '';
			echo '<p><label>' . esc_html( $item->get_name() ) . '<br><input type="text" inputmode="decimal" name="weights[' . esc_attr( (string) $item_id ) . ']" value="' . esc_attr( $value ) . '" required></label></p>';
		}
		echo '<p><label>' . esc_html__( 'دلیل اجباری', 'zarbalman-core' ) . '<br><textarea name="reason" required minlength="10" maxlength="1000" rows="2" style="width:100%"></textarea></label></p>';
		submit_button( $label, 'primary', 'submit', false );
		echo '</form>';
	}

	private function transition_form_open( WC_Order $order, string $to_state ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin:12px 0;padding:10px;border:1px solid #ccd0d4">';
		echo '<input type="hidden" name="action" value="zbm_core_transition"><input type="hidden" name="order_id" value="' . esc_attr( (string) $order->get_id() ) . '">';
		echo '<input type="hidden" name="to_state" value="' . esc_attr( $to_state ) . '"><input type="hidden" name="idempotency_key" value="' . esc_attr( 'admin-' . wp_generate_uuid4() ) . '">';
		echo '<input type="hidden" name="correlation_id" value="' . esc_attr( wp_generate_uuid4() ) . '">';
		wp_nonce_field( 'zbm_core_order_' . $order->get_id() );
	}

	private function render_history( int $order_id ): void {
		echo '<h4>' . esc_html__( 'تاریخچه ممیزی', 'zarbalman-core' ) . '</h4><div style="max-height:260px;overflow:auto"><table class="widefat striped"><thead><tr><th>' . esc_html__( 'زمان GMT', 'zarbalman-core' ) . '</th><th>' . esc_html__( 'رویداد', 'zarbalman-core' ) . '</th><th>' . esc_html__( 'اقدام‌کننده', 'zarbalman-core' ) . '</th><th>' . esc_html__( 'جزئیات', 'zarbalman-core' ) . '</th></tr></thead><tbody>';
		foreach ( $this->audit->for_order( $order_id, 100 ) as $event ) {
			echo '<tr><td>' . esc_html( (string) $event->created_at_gmt ) . '</td><td><code>' . esc_html( (string) $event->event_key ) . '</code></td>';
			echo '<td>' . esc_html( (string) $event->actor_type . ':' . (string) $event->actor_user_id ) . '</td><td>';
			$this->render_event_metadata( (string) ( $event->metadata_json ?? '' ) );
			echo '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	private function deadline_row( string $label, mixed $deadline ): void {
		if ( is_string( $deadline ) && '' !== $deadline ) {
			echo '<p><strong>' . esc_html( $label ) . ':</strong> ' . esc_html( get_date_from_gmt( $deadline, 'Y/m/d H:i:s' ) ) . '</p>';
		}
	}

	private function weight( mixed $tenths ): string {
		return null === $tenths ? '—' : WeightValue::format_tenths( (int) $tenths ) . ' kg';
	}

	private function render_event_metadata( string $json ): void {
		$metadata = json_decode( $json, true );
		if ( ! is_array( $metadata ) ) {
			echo '—';
			return;
		}
		if ( ! empty( $metadata['reason'] ) ) {
			echo '<p>' . esc_html( (string) $metadata['reason'] ) . '</p>';
		}
		if ( ! empty( $metadata['note'] ) ) {
			echo '<p>' . esc_html( (string) $metadata['note'] ) . '</p>';
		}
		if ( ! empty( $metadata['evidence_attachment_ids'] ) && is_array( $metadata['evidence_attachment_ids'] ) ) {
			foreach ( $metadata['evidence_attachment_ids'] as $attachment_id ) {
				$attachment_id = absint( $attachment_id );
				if ( 1 === (int) get_post_meta( $attachment_id, '_zbm_evidence_encrypted', true ) ) {
					$url = $this->evidence_download->url( $attachment_id );
					echo '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer" style="display:inline-block;margin:2px">';
					echo '<img src="' . esc_url( $url ) . '" alt="' . esc_attr__( 'مدرک اعتراض', 'zarbalman-core' ) . '" width="80" height="80" style="object-fit:cover">';
					echo '</a>';
				} else {
					echo '<span class="notice notice-error inline"><span>' . esc_html__( 'این مدرک قدیمی هنوز ایمن‌سازی نشده و قابل نمایش نیست.', 'zarbalman-core' ) . '</span></span>';
				}
			}
		}
		if ( empty( $metadata['reason'] ) && empty( $metadata['note'] ) && empty( $metadata['evidence_attachment_ids'] ) ) {
			echo '<code>' . esc_html( wp_json_encode( $metadata, JSON_UNESCAPED_UNICODE ) ?: '' ) . '</code>';
		}
	}
}
