<?php

namespace App\Http\Requests\Panel;

use App\Models\Voucher;
use App\Rules\Dinars;
use App\Rules\Percent;
use App\Support\Money;
use App\Support\PanelFormat;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class VoucherRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** Codes are matched without regard to case or stray spaces, so they are stored the same way. */
    protected function prepareForValidation(): void
    {
        $this->merge(['code' => Voucher::normaliseCode((string) $this->input('code', ''))]);
    }

    /**
     * @return array<string,array<int,mixed>>
     */
    public function rules(): array
    {
        $voucher = $this->route('voucher');

        return [
            'code' => ['required', 'string', 'max:60', 'regex:/^[\p{L}\p{N}_\-]+$/u', Rule::unique('vouchers', 'code')->ignore($voucher?->id)],
            'name_ar' => ['required', 'string', 'max:190'],
            'name_en' => ['required', 'string', 'max:190'],
            'type' => ['required', Rule::in([Voucher::TYPE_PERCENTAGE, Voucher::TYPE_FIXED, Voucher::TYPE_FREE_SHIPPING])],
            'percent' => ['nullable', new Percent],
            'amount' => ['nullable', new Dinars],
            'max_discount' => ['nullable', new Dinars],
            'min_subtotal' => ['nullable', new Dinars],
            'starts_at' => ['nullable', 'date_format:Y-m-d\TH:i'],
            'ends_at' => ['nullable', 'date_format:Y-m-d\TH:i'],
            'usage_limit' => ['nullable', 'integer', 'min:1', 'max:10000000'],
            'usage_limit_per_customer' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'is_active' => ['nullable', 'boolean'],
            'is_public' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<int,callable>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $type = $this->input('type');

            if ($type === Voucher::TYPE_PERCENTAGE) {
                $basisPoints = Money::parsePercentBasisPoints((string) $this->input('percent'));

                if ($basisPoints === null || $basisPoints < 1) {
                    $validator->errors()->add('percent', __('panel.vouchers.percentRequired'));
                }
            }

            if ($type === Voucher::TYPE_FIXED) {
                $amount = Money::parseFils((string) $this->input('amount'));

                if ($amount === null || $amount < 1) {
                    $validator->errors()->add('amount', __('panel.vouchers.amountRequired'));
                }
            }

            $starts = $this->input('starts_at');
            $ends = $this->input('ends_at');

            if ($starts && $ends
                && Carbon::createFromFormat('Y-m-d\TH:i', $ends, PanelFormat::timezone())
                    ->lt(Carbon::createFromFormat('Y-m-d\TH:i', $starts, PanelFormat::timezone()))) {
                $validator->errors()->add('ends_at', __('panel.products.discountEndsBeforeStart'));
            }
        }];
    }
}
