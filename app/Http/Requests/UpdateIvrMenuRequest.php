<?php

declare(strict_types=1);

namespace App\Http\Requests;

/**
 * Same rules as create; self-reference checks key off the bound `ivrMenu` route parameter.
 */
class UpdateIvrMenuRequest extends StoreIvrMenuRequest {}
