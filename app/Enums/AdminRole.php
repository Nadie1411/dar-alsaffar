<?php

namespace App\Enums;

/**
 * What a member of staff may do in the admin panel.
 *
 *  - Owner: everything, including who else has access.
 *  - Manager: runs the shop — orders, catalogue, offers, delivery, payments,
 *    content — but cannot add or remove staff, nor change the payment keys.
 *  - Staff: handles the day's work — orders, customers and messages.
 */
enum AdminRole: string
{
    case Owner = 'owner';
    case Manager = 'manager';
    case Staff = 'staff';

    /** @return array<int,PanelModule> */
    public function modules(): array
    {
        return match ($this) {
            self::Owner => PanelModule::cases(),
            self::Manager => array_values(array_filter(
                PanelModule::cases(),
                fn (PanelModule $module) => ! in_array($module, [PanelModule::Staff, PanelModule::Gateway], true)
            )),
            self::Staff => [PanelModule::Dashboard, PanelModule::Orders, PanelModule::Customers, PanelModule::Inbox],
        };
    }

    public function allows(PanelModule $module): bool
    {
        return in_array($module, $this->modules(), true);
    }

    public function label(): string
    {
        return __('panel.roles.'.$this->value);
    }
}
