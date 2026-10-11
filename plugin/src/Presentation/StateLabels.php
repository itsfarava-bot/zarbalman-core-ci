<?php

declare(strict_types=1);

namespace Zarbalman\Core\Presentation;

use Zarbalman\Core\Domain\Workflow\WorkflowDefinition;

final class StateLabels {
	public static function get( string $state ): string {
		$labels = array(
			WorkflowDefinition::CREATED => 'ایجادشده',
			WorkflowDefinition::DEPOSIT_PENDING => 'در انتظار پیش‌پرداخت',
			WorkflowDefinition::DEPOSIT_PAID => 'پیش‌پرداخت انجام‌شده',
			WorkflowDefinition::SUPPLIER_REVIEW => 'در انتظار بررسی تأمین‌کننده',
			WorkflowDefinition::PREPARING => 'در حال آماده‌سازی',
			WorkflowDefinition::IN_TRANSIT => 'ارسال‌شده',
			WorkflowDefinition::DELIVERED => 'تحویل اعلام‌شده',
			WorkflowDefinition::BUYER_WEIGHT_RECORDED => 'وزن خریدار ثبت‌شده',
			WorkflowDefinition::SELLER_WEIGHT_CONFIRMED => 'وزن نهایی تأییدشده',
			WorkflowDefinition::WEIGHT_DISPUTED => 'اختلاف وزن',
			WorkflowDefinition::ADMIN_REVIEW => 'در انتظار بررسی مدیریت',
			WorkflowDefinition::BALANCE_PENDING => 'در انتظار پرداخت مانده',
			WorkflowDefinition::BALANCE_PAID => 'مانده پرداخت‌شده',
			WorkflowDefinition::ACCEPTANCE_WINDOW => 'در حال نهایی‌سازی',
			WorkflowDefinition::DISPUTE_OPEN => 'اعتراض باز',
			WorkflowDefinition::CANCELLATION_REQUESTED => 'درخواست لغو',
			WorkflowDefinition::RETURN_COORDINATION => 'هماهنگی بازگشت کالا',
			WorkflowDefinition::COMPLETED => 'تکمیل‌شده',
			WorkflowDefinition::CANCELLED => 'لغوشده',
		);
		return $labels[ $state ] ?? $state;
	}
}
