<?php

namespace Ripoo;

use Ripoo\Exception\RipooExceptionInterface;
use Ripoo\Exception\AuthException;
use Ripoo\Exception\OdooFault;
use Ripoo\Exception\CodingException;
use Ripoo\Exception\ResponseStatusException;
use Ripoo\Service\CommonServiceInterface;
use Ripoo\Service\DbServiceInterface;
use Ripoo\Service\ModelServiceInterface;

use Ripcord\Ripcord;
use Ripcord\Client\Client as RipcordClient;

/**
 * Uses ripcord for Odoo 11.0
 * @see https://www.odoo.com/documentation/11.0/webservices/odoo.html
 * @see https://github.com/DarkaOnLine/Ripcord
 * @see https://github.com/robroypt/odoo-client
 *
 * @author Thomas Bondois
 */
class Client
{
    const DEFAULT_API_TYPE = 'xmlrpc/2';

    /**
     * Host to connect to
     * @var string
     */
    private $url;

    /**
     * Unique identifier for current user
     * @var integer
     */
    private $uid;

    /**
     * Current users username
     * @var string
     */
    private $user;

    /**
     * Current database
     * @var string
     */
    private $db;

    /**
     * Password for current user
     * @var string
     */
    private $password;

    /**
     * micro timestamp
     * @var float
     */
    private $createdAt;

    /**
     * unique client instance identifier
     * @var string
     */
    private $pid;

    /**
     * @var string
     */
    private $currentEndpoint = null;

    /**
     * For Cache purpose, associative array('endpoint' => Client)
     * @var RipcordClient[]
     */
    private $endpoints = [];


    /**
     * @param string $url The Odoo url. Must contain the protocol like https://, can also :port or /sub/directories
     * @param ?string $db PostgreSQL database of Odoo to log into
     * @param ?string $user The username (Odoo 11 : is email)
     * @param ?string $password Password of the user
     * @param ?string $apiType if not using xmlrpc/2
     */
    public function __construct(string $url, $db = null, $user = null, $password = null, $apiType = null)
    {
        // use customer or default API :
        $apiType = trim($apiType ?? self::DEFAULT_API_TYPE, ' /');

        // clean host if it have a final slash :
        $url    = trim($url, ' /');

        $this->url       = $url . '/' . $apiType;
        $this->db        = $db;
        $this->user      = $user;
        $this->password  = $password;
        $this->createdAt = microtime(true);
        $this->pid       = '#'.$apiType.'-'.microtime(true)."-".mt_rand(10000, 99000);
    }

    /**
     * @param bool $raw 0 = formatted date, 1 = float (micro timestamp)
     * @return mixed
     */
    public function getCreatedAt($raw = false)
    {
        if (!$raw) {
            return date('Y-m-d H:i:s', $this->createdAt);
        }
        return $this->createdAt;
    }

    /**
     * @return string
     */
    public function getPid()
    {
        return $this->pid;
    }

    /**
     * Get uid
     * @param bool $reset
     * @return int $uid
     * @throws AuthException
     * @throws OdooFault
     */
    private function uid(bool $reset = false) : int
    {
        if ($this->uid === null || $reset) {
            $common = $this->getRipcordClient('common');
            $this->uid = $common->authenticate(
                $this->db, $this->user, $this->password,
                []
            );

            if (!is_int($this->uid)) {
                if (is_array($this->uid) && array_key_exists('faultCode', $this->uid)) {
                    throw new OdooFault($this->uid['faultString'], $this->uid['faultCode']);
                } else {
                    throw new AuthException('Unsuccessful Authorization');
                }
            }
        }
        return $this->uid;
    }

    /**
     * @param bool $reset
     * @return bool
     * @author Thomas Bondois
     */
    public function testAuthenticate(bool $reset = false) : bool
    {
        try {
            if ($this->uid($reset)) {
                return true;
            }
        } catch (\Throwable $e) {
        }
        return false;
    }

    /**
     * Get version
     *
     * @return array
     * @throws OdooFault
     */
    public function version()
    {
        $response = $this->getRipcordClient('common')->version();
        //$response = $this->getCommonService()->version(); // TODO understand why crash Odoo
        return $this->checkResponse($response);
    }

    /**
     * @param string $model
     * @param string $permission ex: 'read'
     * @param bool $withExceptions
     * @return bool
     * @author Thomas Bondois
     */
    public function check_access_rights(string $model, string $permission = 'read', bool $withExceptions = false) : bool
    {
        if (!is_array($permission)) {
            $permission = [$permission];
        }
        try {
            $response = $this->getModelService()->execute_kw(
                $this->db, $this->uid(), $this->password,
                $model,
                'check_access_rights',
                $permission,
                ['raise_exception' => $withExceptions]
            );

            //TODO analyse result fault etc
            return (bool)$this->checkResponse($response);

        } catch (RipooExceptionInterface $exception) {
        }
        return false;
    }

    /**
     * Search models
     *
     * @param string $model Model
     * @param array $criteria Array of criteria
     * @param integer $offset Offset
     * @param integer $limit Max results
     *
     * @return array Array of model id's
     * @throws AuthException
     * @throws OdooFault
     */
    public function search(string $model, array $criteria, $offset = 0, $limit = 100, $order = '')
    {
        $response = $this->getModelService()->execute_kw(
            $this->db, $this->uid(), $this->password,
            $model,
            'search',
            [$criteria],
            ['offset' => $offset, 'limit' => $limit, 'order' => $order]
        );
        return $this->checkResponse($response);
    }

    /**
     * Search_count models
     *
     * @param string $model Model
     * @param array $criteria Array of criteria
     *
     * @return array Array of model id's
     * @throws AuthException
     * @throws OdooFault
     */
    public function search_count(string $model, array $criteria)
    {
        $response = $this->getModelService()->execute_kw(
            $this->db, $this->uid(), $this->password,
            $model,
            'search_count',
            [$criteria]
        );
        return $response;
    }

    /**
     * Read model(s)
     *
     * @param string $model Model
     * @param array $ids Array of model id's
     * @param array $fields Index array of fields to fetch, an empty array fetches all fields
     *
     * @return array An array of models
     * @throws AuthException
     * @throws OdooFault
     */
    public function read(string $model, array $ids, array $fields = [])
    {
        $response = $this->getModelService()->execute_kw(
            $this->db, $this->uid(), $this->password,
            $model,
            'read',
            [$ids],
            ['fields' => $fields]
        );
        return $response;
    }

    /**
     * Search and Read model(s)
     *
     * @param string $model Model
     * @param array $criteria Array of criteria
     * @param array $fields Index array of fields to fetch, an empty array fetches all fields
     * @param integer $limit Max results
     * @param string $order
     *
     * @return array An array of models
     * @throws AuthException
     * @throws OdooFault
     */
    public function search_read(string $model, array $criteria, array $fields = [], int $limit = 100, $order = '')
    {
        $response = $this->getModelService()->execute_kw(
            $this->db, $this->uid(), $this->password,
            $model,
            'search_read',
            [$criteria],
            [
                'fields' => $fields,
                'limit' => $limit,
                'order' => $order,
            ]
        );
        return $response;
    }

    /**
     * @see https://www.odoo.com/documentation/11.0/reference/orm.html#odoo.models.Model.fields_get
     * @param string $model
     * @param array $fields
     * @param array $attributes
     * @return mixed
     * @throws AuthException
     * @throws OdooFault
     * @author Thomas Bondois
     */
    public function fields_get(string $model, array $fields = [], array $attributes = [])
    {
        $response = $this->getModelService()->execute_kw(
            $this->db, $this->uid(), $this->password,
            $model,
            'fields_get',
            $fields,
            ['attributes' => $attributes]
        );
        return $response;
    }

    /**
     * Create model
     *
     * @param string $model Model
     * @param array $data Array of fields with data (format: ['field' => 'value'])
     *
     * @return int Created model id
     * @throws AuthException
     * @throws OdooFault
     */
    public function create(string $model, $data)
    {
        $response = $this->getModelService()->execute_kw(
            $this->db, $this->uid(), $this->password,
            $model,
            'create',
            [$data]
        );
        return $response;
    }

    /**
     * Update model(s)
     *
     * @param string $model Model
     * @param array $ids Model ids to update
     * @param array $fields A associative array (format: ['field' => 'value'])
     *
     * @return array
     * @throws AuthException
     * @throws OdooFault
     */
    public function write(string $model, $ids, $fields)
    {
        $response = $this->getModelService()->execute_kw(
            $this->db, $this->uid(), $this->password,
            $model,
            'write',
            [
                $ids,
                $fields,
            ]
        );
        return $response;
    }

    /**
     * Unlink model(s)
     *
     * @param string $model Model
     * @param array $ids Array of model id's
     *
     * @return boolean True is successful
     * @throws AuthException
     * @throws OdooFault
     */
    public function unlink(string $model, $ids)
    {
        $response = $this->getModelService()->execute_kw(
            $this->db, $this->uid(), $this->password,
            $model,
            'unlink',
            [$ids]
        );
        return $this->checkResponse($response);
    }

    /**
     * @return string
     * @throws OdooFault
     */
    public function server_version()
    {
        $response = $this->getDbService()->server_version();
        return $this->checkResponse($response);
    }

    /**
     * Get XmlRpc Client
     *
     * This method returns an XmlRpc Client for the requested endpoint.
     * If no endpoint is specified or if a client for the requested endpoint is
     * already initialized, the last used client will be returned.
     *
     * @param string $endpoint The api endpoint
     *
     * @return RipcordClient
     */
    public function getRipcordClient(string $endpoint) : RipcordClient
    {
        $endpoint = trim($endpoint, " /");
        if (!empty($this->endpoints[$endpoint])) {
            return $this->endpoints[$endpoint];
        }
        $this->endpoints[$endpoint] = Ripcord::client($this->url.'/'.$endpoint);
        $this->currentEndpoint = $endpoint;
        return $this->endpoints[$endpoint];
    }

    public function getCurrentRipcordClient(): RipcordClient
    {
        if (!$this->currentEndpoint || empty($this->endpoints[$this->currentEndpoint])) {
            throw new CodingException("Need to make a first call before getting the current client");
        }
        return $this->endpoints[$this->currentEndpoint];
    }


    /**
     * odoo.service.common.dispatch
     * @see https://github.com/odoo/odoo/blob/11.0/odoo/service/common.py
     *
     * TODO understand why odoo return fault (UnboundLocalError: local variable 'dispatch' referenced before assignment),
     * if we use getCommonService()->version, and no fault if we we getRipcordClient('commmon')->version()
     *
     * @return RipcordClient|CommonServiceInterface
     * @author Thomas Bondois
     */
    private function getCommonService()
    {
        return $this->getRipcordClient('commmon');
    }

    /**
     * odoo.service.db.dispatch
     * @see https://github.com/odoo/odoo/blob/11.0/odoo/service/db.py
     * @return RipcordClient|DbServiceInterface
     */
    private function getDbService()
    {
        return $this->getRipcordClient('db');
    }

    /**
     * "Object" Endpoint, "Model" service
     * odoo.service.model.dispatch
     * @see https://github.com/odoo/odoo/blob/11.0/odoo/service/model.py
     * @return RipcordClient|ModelServiceInterface
     * @author Thomas Bondois
     */
    private function getModelService()
    {
        return $this->getRipcordClient('object');
    }

    /**
     * Throw exception in case it contains an error
     * @TODO check "status", "status_message"
     * @param mixed $response
     * @return mixed
     * @throws OdooFault
     * @author Thomas Bondois
     */
    public function checkResponse($response)
    {
        if (is_array($response)) {
            if (isset($response['faultCode'])) {
                $faultCode = $response['faultCode'];
                $faultString = $response['faultString'] ?? '';
                throw new OdooFault($faultString, $faultCode);
            }
        }
        return $response;
    }

} // end class
