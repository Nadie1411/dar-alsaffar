<?php

namespace App\Enums;

/**
 * The areas of the admin panel, each of which a role may or may not open.
 */
enum PanelModule: string
{
    case Dashboard = 'dashboard';
    case Orders = 'orders';
    case Customers = 'customers';
    case Inbox = 'inbox';
    case Products = 'products';
    case Categories = 'categories';
    case Addons = 'addons';
    case Vouchers = 'vouchers';
    case Delivery = 'delivery';
    case Payments = 'payments';
    case Content = 'content';
    case Reports = 'reports';
    case Staff = 'staff';
    /** The payment gateway's keys. Whoever holds them decides where customers' money goes. */
    case Gateway = 'gateway';
    case Log = 'log';

    public function label(): string
    {
        return __('panel.modules.'.$this->value);
    }
}
