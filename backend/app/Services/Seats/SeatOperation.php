<?php

namespace App\Services\Seats;

/** What a request wants from the seat budget: a new reservation (an invitation) or a seat actually taken (access granted). */
enum SeatOperation: string
{
    case Reserve = 'reserve';
    case Take = 'take';
}
