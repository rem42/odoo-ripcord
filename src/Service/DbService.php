<?php

namespace Ripoo\Service;

use Ripcord\Client\Client as RipcordClient;

/**
 * Reflect of Odoo Db Service generated by client request
 * @see https://github.com/odoo/odoo/blob/11.0/odoo/service/db.py
 *
 * @method server_version() : string
 * @method db_exist($db_name) : bool
 * @method list($document = false) : array
 * @method list_lang() : array
 * @method list_countries() : array
 *
 * @author Thomas Bondois
 */
class DbService extends RipcordClient
{
    const ENDPOINT    = 'db';

    /**
     * Display last response XML content
     * @return string
     */
    public function getRawResponse()
    {
        return $this->_response;
    }

} // end class
