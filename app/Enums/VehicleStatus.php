<?php

namespace App\Enums;

enum VehicleStatus: string
{
    case Active = 'active';
    case Maintenance = 'maintenance';
    case Inactive = 'inactive';
    case Sold = 'sold';
    case Disposed = 'disposed';
}
