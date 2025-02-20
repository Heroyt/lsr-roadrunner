<?php
declare(strict_types=1);

namespace Lsr\Roadrunner\ErrorHandlers;

use Lsr\Core\Requests\Request;

trait BaseHttpErrorHandler
{

    /**
     * @param  Request  $request
     * @return string[]
     */
    protected function getAcceptTypes(Request $request) : array {
        $types = [];
        foreach ($request->getHeader('Accept') as $value) {
            $str = strtolower(trim(explode(';', $value, 2)[0]));
            if ($str === '') {
                continue;
            }
            $types[] = $str;
        }
        return $types;
    }

}