<?php

declare(strict_types=1);

namespace Zarbalman\Core\Admin;

use wpdb;
use Zarbalman\Core\Application\Spec4FinanceService;
use Zarbalman\Core\Frontend\{ManagedCasePanel, RequestPresenter, RequestResponse};
use Zarbalman\Core\Infrastructure\{ManagedCaseRepository, Migrator, PurchaseRequestRepository, Schema};
use Zarbalman\Core\Security\AdminCapabilities;

/** Finance queues and ledger export contain no decrypted bank destination or public evidence URL. */
final class Spec4FinancePage {
	public const SLUG = 'zbm-core-finance';
	private Schema $schema;
	public function __construct( private readonly wpdb $db, private readonly PurchaseRequestRepository $requests, private readonly ManagedCaseRepository $cases, private readonly RequestPresenter $presenter, private readonly ManagedCasePanel $panel, private readonly Spec4FinanceService $finance ) { $this->schema = new Schema( $db ); }
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ), 64 );
		add_action( 'admin_post_zbm_finance_export', array( $this, 'export' ) );
		add_action( 'admin_post_zbm_finance_destination', array( $this, 'destination' ) );
	}
	public function menu(): void { add_submenu_page( 'woocommerce', __( 'مرکز مالی زربال‌من', 'zarbalman-core' ), __( 'مرکز مالی زربال‌من', 'zarbalman-core' ), AdminCapabilities::MANAGE_FINANCE, self::SLUG, array( $this, 'render' ) ); }
	private function authorize(): void {
		RequestResponse::preventCaching();
		if ( ! AdminCapabilities::currentUserCanManageFinance() ) { wp_die( esc_html__( 'دسترسی مالی لازم است.', 'zarbalman-core' ), '', array( 'response' => 403 ) ); }
		if ( $error = Migrator::financeAvailabilityError() ) { wp_die( esc_html( $error->get_error_message() ) ); }
	}
	public function render(): void {
		$this->authorize();
		$id = self::integer( $_GET['request'] ?? null ) ?? 0;
		$selected = $id > 0 ? $this->requests->find( $id ) : null;
		$queue = is_string( $_GET['queue'] ?? null ) ? sanitize_key( wp_unslash( $_GET['queue'] ) ) : 'active';
		$substate = "JSON_UNQUOTE(JSON_EXTRACT(data_json, '$.spec4.substate'))";
		$settlement = "JSON_UNQUOTE(JSON_EXTRACT(data_json, '$.final.settlement'))";
		// All predicates are server-owned constants, never interpolated request input.
		$filters = array(
			'active' => "(state = 'final_weight_locked' OR ($substate IS NOT NULL AND $substate <> 'closed' AND (state <> 'completed' OR $settlement <> 'credited')))",
			'balance' => "(state = 'final_weight_locked' OR $substate IN ('ready','balance_due','receipt_review'))",
			'contact' => "$substate IN ('contact_due','warning_elapsed')",
			'warning' => "$substate = 'warning'",
			'return' => "JSON_UNQUOTE(JSON_EXTRACT(data_json, '$.spec4.trigger')) = 'nonpayment_return'",
			'refund' => "$substate = 'refund_pending'",
			'credit' => "state = 'completed' AND $substate = 'completed' AND $settlement <> 'credited'",
			'review' => "($substate = 'payment_review' OR JSON_UNQUOTE(JSON_EXTRACT(data_json, '$.spec4.reconciliation_status')) = 'review')",
			'completed' => "state IN ('completed','cancelled') AND $substate IS NOT NULL",
		);
		$queue_labels = array( 'active' => 'در جریان', 'balance' => 'مانده و رسید', 'contact' => 'نیازمند تماس', 'warning' => 'هشدار ۳۰ دقیقه', 'return' => 'بازگشت تأییدشده', 'refund' => 'بازپرداخت', 'credit' => 'بازیابی اعتبار', 'review' => 'مغایرت مالی', 'completed' => 'تکمیل یا بسته‌شده' );
		if ( ! isset( $filters[ $queue ] ) ) { $queue = 'active'; }
		$before = self::integer( $_GET['before'] ?? null ) ?? PHP_INT_MAX;
		$ids = $this->db->get_col( $this->db->prepare( 'SELECT request_id FROM %i WHERE mode = %s AND request_id < %d AND (' . $filters[ $queue ] . ') ORDER BY request_id DESC LIMIT 25', $this->schema->managed_cases_table(), 'live', $before ) );
		if ( ! is_array( $ids ) || '' !== $this->db->last_error ) { wp_die( esc_html__( 'فهرست مالی موقتاً در دسترس نیست.', 'zarbalman-core' ) ); }
		?>
		<div class="wrap zbm-admin-shell" dir="rtl"><div class="zbm-admin-hero"><div><p class="zbm-admin-eyebrow"><?php esc_html_e( 'زربال‌من • پیگیری مالی', 'zarbalman-core' ); ?></p><h1><?php esc_html_e( 'مرکز مالی سفارش‌ها', 'zarbalman-core' ); ?></h1><p><?php esc_html_e( 'پرداخت مانده، پیگیری تماس، بازگشت کالا و بازپرداخت را از یک پرونده دنبال کنید.', 'zarbalman-core' ); ?></p></div></div>
		<nav class="zbm-spec4-tabs" aria-label="<?php esc_attr_e( 'صف‌های مالی', 'zarbalman-core' ); ?>"><?php foreach ( $queue_labels as $value => $label ) : ?><a class="button <?php echo $value === $queue ? 'button-primary' : ''; ?>" href="<?php echo esc_url( add_query_arg( array( 'page' => self::SLUG, 'queue' => $value ), admin_url( 'admin.php' ) ) ); ?>"><?php echo esc_html( $label ); ?></a><?php endforeach; ?></nav>
		<?php if ( $selected ) : ?>
			<p><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ); ?>"><?php esc_html_e( 'بازگشت به مرکز مالی', 'zarbalman-core' ); ?></a></p>
			<?php echo $this->panel->render( $selected, 'admin', get_current_user_id() ); // Role-safe escaped template. ?>
		<?php else : ?>
		<section class="zbm-admin-card"><div class="zbm-spec4-table-scroll"><table class="widefat striped"><thead><tr><th><?php esc_html_e( 'درخواست', 'zarbalman-core' ); ?></th><th><?php esc_html_e( 'محصول', 'zarbalman-core' ); ?></th><th><?php esc_html_e( 'مبالغ و طرفین', 'zarbalman-core' ); ?></th><th><?php esc_html_e( 'وضعیت و مهلت', 'zarbalman-core' ); ?></th><th><?php esc_html_e( 'پیگیری', 'zarbalman-core' ); ?></th></tr></thead><tbody>
		<?php foreach ( $ids as $request_id ) : $request = $this->requests->find( (int) $request_id ); if ( ! $request ) { continue; } $view = $this->presenter->present( $request ); $case = $this->cases->find( $request->id() ); ?>
			<tr><td>#<?php echo esc_html( $request->requestNumber() ); ?></td><td><?php echo esc_html( $view['product_name'] ); ?></td><td><?php
			$basis = $case['data']['spec4']['basis_toman'] ?? $case['data']['final']['total'] ?? $request->estimatedGrossToman();
			$received = ( $case['data']['funds']['deposit']['amount'] ?? 0 ) + ( $case['data']['funds']['balance']['amount'] ?? 0 );
			echo esc_html( 'پایه: ' . number_format_i18n( $basis ) . ' تومان' ); ?><small><?php echo esc_html( 'وصول: ' . number_format_i18n( $received ) . ' تومان' . ( empty( $case['data']['spec4']['trigger'] ) ? ' — مانده: ' . number_format_i18n( $basis - $received ) . ' تومان' : ' — تخصیص و استرداد طبق پرونده' ) ); ?></small><small><?php echo esc_html( 'خریدار #' . $request->buyerId() . ' · فروشنده #' . $request->vendorId() . ' · سفارش #' . ( $request->wcOrderId() ?? 0 ) ); ?></small></td><td><?php echo esc_html( $view['state_label'] ); ?><?php if ( is_string( $case['deadline_gmt'] ?? null ) ) : ?><small><?php echo esc_html( \Zarbalman\Core\Support\JalaliDate::formatGmt( $case['deadline_gmt'], wp_timezone(), true ) ); ?></small><?php endif; ?><?php if ( 'completed' === ( $case['state'] ?? '' ) && 'credited' !== ( $case['data']['final']['settlement'] ?? '' ) ) : ?><br><strong><?php esc_html_e( 'ثبت اعتبار فروشنده در انتظار بازیابی', 'zarbalman-core' ); ?></strong><?php endif; ?></td><td><a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => self::SLUG, 'request' => $request->id() ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'بررسی پرونده', 'zarbalman-core' ); ?></a></td></tr>
		<?php endforeach; if ( ! $ids ) : ?><tr><td colspan="5"><?php esc_html_e( 'پرونده‌ای در این صف نیست.', 'zarbalman-core' ); ?></td></tr><?php endif; ?>
		</tbody></table></div>
		<?php if ( count( $ids ) === 25 ) : ?><p><a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => self::SLUG, 'queue' => $queue, 'before' => (int) end( $ids ) ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'پرونده‌های قدیمی‌تر', 'zarbalman-core' ); ?></a></p><?php endif; ?></section>
		<?php endif; ?>
		<section class="zbm-admin-card"><h2><?php esc_html_e( 'خروجی دفتر مالی برای حسابداری', 'zarbalman-core' ); ?></h2><p><?php esc_html_e( 'دریافت وجه، تخصیص سهم و استرداد رویدادهای متفاوت‌اند؛ ستون نوع رویداد را در حسابداری ملاک قرار دهید. هر خروجی حداکثر ۵۰۰ ردیف دارد.', 'zarbalman-core' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="zbm_finance_export"><?php wp_nonce_field( 'zbm_finance_export', '_zbm_nonce' ); ?><label><?php esc_html_e( 'ردیف‌های پیش از شناسه (برای ادامهٔ خروجی؛ اختیاری)', 'zarbalman-core' ); ?><input name="before" inputmode="numeric" pattern="[0-9]+"></label><button class="button" type="submit"><?php esc_html_e( 'دریافت CSV دفتر مالی', 'zarbalman-core' ); ?></button></form></section>
		</div><?php
	}
	public function export(): never {
		$this->authorize();
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! RequestResponse::verifyFormNonce( $_POST['_zbm_nonce'] ?? null, 'zbm_finance_export' ) ) { wp_die( esc_html__( 'فرم معتبر نیست.', 'zarbalman-core' ), '', array( 'response' => 403 ) ); }
		$before = self::integer( $_POST['before'] ?? null ) ?? PHP_INT_MAX;
		$rows = $this->db->get_results( $this->db->prepare( 'SELECT l.id,l.request_id,l.order_id,l.payment_id,l.entry_type,l.owner_type,l.vendor_id,l.amount_toman,l.currency,l.status,l.created_at_gmt,a.id AS financial_action_id,a.external_reference,a.created_by,a.approved_by,a.executed_by,a.reconciled_by FROM %i l LEFT JOIN %i a ON a.ledger_entry_id = l.id WHERE l.id < %d ORDER BY l.id DESC LIMIT 500', $this->schema->ledger_table(), $this->schema->financial_actions_table(), $before ), ARRAY_A );
		if ( ! is_array( $rows ) || '' !== $this->db->last_error ) { wp_die( esc_html__( 'خروجی مالی ساخته نشد.', 'zarbalman-core' ) ); }
		header( 'Content-Type: text/csv; charset=UTF-8' ); header( 'Content-Disposition: attachment; filename="zarbalman-ledger-' . gmdate( 'Ymd-His' ) . '.csv"' );
		$out = fopen( 'php://output', 'wb' ); if ( false === $out ) { exit; } fwrite( $out, "\xEF\xBB\xBF" );
		fputcsv( $out, array( 'ledger_id', 'request_id', 'order_id', 'payment_id', 'entry_type', 'owner_type', 'vendor_id', 'amount_toman', 'currency', 'status', 'created_at_utc', 'financial_action_id', 'external_reference', 'created_by', 'approved_by', 'executed_by', 'reconciled_by' ), ',', '"', '' );
		foreach ( $rows as $row ) { fputcsv( $out, array_map( static function ( mixed $value ): string { $text = (string) $value; return preg_match( '/\A[\s]*[=+@-]/u', $text ) || preg_match( '/\A[\t\r\n]/', $text ) ? "'" . $text : $text; }, array_values( $row ) ), ',', '"', '' ); }
		fclose( $out ); exit;
	}
	public function destination(): never {
		$this->authorize();
		$id = self::integer( $_POST['request_id'] ?? null ) ?? 0; $version = self::integer( $_POST['version'] ?? null ) ?? 0; $action = self::integer( $_POST['financial_action_id'] ?? null ) ?? 0;
		$key = is_string( $_POST['idempotency_key'] ?? null ) ? wp_unslash( $_POST['idempotency_key'] ) : '';
		$reason = is_string( $_POST['reason'] ?? null ) ? wp_unslash( $_POST['reason'] ) : '';
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! RequestResponse::verifyFormNonce( $_POST['_zbm_nonce'] ?? null, 'zbm_finance_destination_' . $id . '_' . $action ) ) { wp_die( esc_html__( 'فرم معتبر نیست.', 'zarbalman-core' ), '', array( 'response' => 403 ) ); }
		$result = $this->finance->destination( $id, $version, $action, get_current_user_id(), $key, $reason );
		wp_die( is_wp_error( $result ) ? esc_html( $result->get_error_message() ) : '<p>' . esc_html__( 'شبای ثبت‌شده برای این بازپرداخت؛ مشاهده با نام شما ثبت شد.', 'zarbalman-core' ) . '</p><p dir="ltr"><strong>' . esc_html( $result ) . '</strong></p><p>' . esc_html__( 'برای ادامهٔ عملیات، صفحهٔ پرونده را تازه کنید.', 'zarbalman-core' ) . '</p>', esc_html__( 'مقصد خصوصی بازپرداخت', 'zarbalman-core' ), array( 'response' => is_wp_error( $result ) ? 409 : 200 ) );
	}
	private static function integer( mixed $value ): ?int { $value = is_string( $value ) ? $value : ''; if ( ! preg_match( '/\A[1-9][0-9]*\z/', $value ) || strlen( $value ) > strlen( (string) PHP_INT_MAX ) || strlen( $value ) === strlen( (string) PHP_INT_MAX ) && strcmp( $value, (string) PHP_INT_MAX ) > 0 ) { return null; } return (int) $value; }
}
