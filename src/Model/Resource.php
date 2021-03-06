<?php

namespace Platformsh\Client\Model;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Message\RequestInterface;
use Platformsh\Client\Exception\ApiResponseException;

class Resource implements \ArrayAccess
{

    /** @var array */
    protected static $required = [];

    /** @var ClientInterface */
    protected $client;

    /** @var array */
    protected $data;

    /**
     * @param array           $data
     * @param ClientInterface $client
     */
    public function __construct(array $data = [], ClientInterface $client = null)
    {
        $this->data = $data;
        $this->client = $client ?: new Client();
    }

    /**
     * @inheritdoc
     */
    public function offsetExists($offset) {
        return $this->propertyExists($offset);
    }

    /**
     * @inheritdoc
     */
    public function offsetGet($offset) {
        return $this->getProperty($offset);
    }

    /**
     * @inheritdoc
     *
     * @throws \BadMethodCallException
     */
    public function offsetSet($offset, $value) {
        throw new \BadMethodCallException('Properties are read-only');
    }

    /**
     * @inheritdoc
     *
     * @throws \BadMethodCallException
     */
    public function offsetUnset($offset) {
        throw new \BadMethodCallException('Properties are read-only');
    }

    /**
     * Get all of the API data for this resource.
     *
     * @return array
     */
    public function getData()
    {
        return $this->data;
    }

    /**
     * Ensure that this is a full representation of the resource (not a stub).
     */
    public function ensureFull()
    {
        if (empty($this->data['_full'])) {
            $this->refresh();
        }
    }

    /**
     * Get a resource by its ID.
     *
     * @param string          $id
     * @param string          $collectionUrl
     * @param ClientInterface $client
     *
     * @return static|false
     */
    public static function get($id, $collectionUrl, ClientInterface $client)
    {
        try {
            $url = $collectionUrl ? rtrim($collectionUrl, '/') . '/' . $id : $id;
            $request = $client->createRequest('get', $url);
            $response = self::send($request, $client);
            $data = $response->json();
            $data['_full'] = true;

            return static::wrap($data, $client);
        } catch (BadResponseException $e) {
            $response = $e->getResponse();
            if ($response && $response->getStatusCode() === 404) {
                return false;
            }
            throw $e;
        }
    }

    /**
     * Create a resource.
     *
     * @param array           $body
     * @param string          $collectionUrl
     * @param ClientInterface $client
     *
     * @return static
     */
    public static function create(array $body, $collectionUrl, ClientInterface $client)
    {
        if ($errors = static::check($body)) {
            $message = "Cannot create resource due to validation error(s): " . implode('; ', $errors);
            throw new \InvalidArgumentException($message);
        }

        $request = $client->createRequest('post', $collectionUrl, ['json' => $body]);
        $response = self::send($request, $client);
        $data = (array) $response->json();
        $data['_full'] = true;

        return static::wrap($data, $client);
    }

    /**
     * Send a Guzzle request.
     *
     * Using this method allows exceptions to be standardized.
     *
     * @param RequestInterface $request
     * @param ClientInterface  $client
     *
     * @return \GuzzleHttp\Message\ResponseInterface
     */
    public static function send(RequestInterface $request, ClientInterface $client)
    {
        try {
            return $client->send($request);
        } catch (BadResponseException $e) {
            throw ApiResponseException::create($e->getRequest(), $e->getResponse());
        }
    }

    /**
     * Get the required properties for creating a new resource.
     *
     * @return array
     */
    public static function getRequired()
    {
        return static::$required;
    }

    /**
     * Validate a new resource.
     *
     * @param array $data
     *
     * @return string[] An array of validation errors.
     */
    public static function check(array $data)
    {
        $errors = [];
        if ($missing = array_diff(static::getRequired(), array_keys($data))) {
            $errors[] = 'Missing: ' . implode(', ', $missing);
        }
        return $errors;
    }

    /**
     * Get a collection of resources.
     *
     * @param string          $url
     * @param int             $limit
     * @param array           $options
     * @param ClientInterface $client
     *
     * @return static[]
     */
    public static function getCollection($url, $limit = 0, array $options = [], ClientInterface $client)
    {
        if ($limit) {
            // @todo uncomment this when the API implements a 'count' parameter
            // $options['query']['count'] = $limit;
        }
        $request = $client->createRequest('get', $url, $options);
        $data = self::send($request, $client)->json();

        // @todo remove this when the API implements a 'count' parameter
        if ($limit) {
            $data = array_slice($data, 0, $limit);
        }

        return static::wrapCollection($data, $client);
    }

    /**
     * Create a resource instance from JSON data.
     *
     * @param array           $data
     * @param ClientInterface $client
     *
     * @return static
     */
    public static function wrap(array $data, ClientInterface $client)
    {
        return new static($data, $client);
    }

    /**
     * Create an array of resource instances from a collection's JSON data.
     *
     * @param array           $data
     * @param ClientInterface $client
     *
     * @return static[]
     */
    public static function wrapCollection(array $data, ClientInterface $client)
    {
        return array_map(
          function ($item) use ($client) {
              return new static($item, $client);
          },
          $data
        );
    }

    /**
     * Execute an operation on the resource.
     *
     * @param string $op
     * @param string $method
     * @param array  $body
     *
     * @return array
     */
    protected function runOperation($op, $method = 'post', array $body = [])
    {
        if (!$this->operationAvailable($op)) {
            throw new \RuntimeException("Operation not available: $op");
        }
        $options = [];
        if (!empty($body)) {
            $options['json'] = $body;
        }
        $request = $this->client
          ->createRequest($method, $this->getLink("#$op"), $options);
        $response = $this->client->send($request);

        return (array) $response->json();
    }

    /**
     * Run a long-running operation.
     *
     * @param string $op
     * @param string $method
     * @param array  $body
     *
     * @throws \Exception
     *
     * @return Activity
     */
    protected function runLongOperation($op, $method = 'post', array $body = [])
    {
        $data = $this->runOperation($op, $method, $body);
        if (!isset($data['_embedded']['activities'][0])) {
            throw new \Exception('Expected activity not found');
        }

        return Activity::wrap($data['_embedded']['activities'][0], $this->client);
    }

    /**
     * Check whether a property exists in the resource.
     *
     * @param string $property
     *
     * @return bool
     */
    public function propertyExists($property)
    {
        return $this->isProperty($property) && array_key_exists($property, $this->data);
    }

    /**
     * Get a property of the resource.
     *
     * @param string $property
     *
     * @throws \InvalidArgumentException
     *
     * @return mixed
     */
    public function getProperty($property)
    {
        if (!$this->offsetExists($property)) {
            throw new \InvalidArgumentException("Property not found: $property");
        }

        return $this->data[$property];
    }

    /**
     * Delete the resource.
     *
     * @return array
     */
    public function delete()
    {
        return $this->client->delete($this->getUri())->json();
    }

    /**
     * Update the resource.
     *
     * This updates the resource's internal data with the API response.
     *
     * @param array $values
     */
    public function update(array $values)
    {
        $data = $this->runOperation('edit', 'patch', $values);
        if (isset($data['_embedded']['entity'])) {
            $this->data = $data['_embedded']['entity'];
            $this->data['_full'] = true;
        }
    }

    /**
     * Get the resource's URI.
     *
     * @param bool $absolute
     *
     * @return string
     */
    public function getUri($absolute = false)
    {
        return $this->getLink('self', $absolute);
    }

    /**
     * Refresh the resource.
     *
     * @param array $options
     */
    public function refresh(array $options = [])
    {
        $request = $this->client->createRequest('get', $this->getUri(), $options);
        $response = self::send($request, $this->client);
        $this->data = (array) $response->json();
        $this->data['_full'] = true;
    }

    /**
     * Check whether an operation is available on the resource.
     *
     * @param string $op
     *
     * @return bool
     */
    public function operationAvailable($op)
    {
        return isset($this->data['_links']["#$op"]['href']);
    }

    /**
     * Check whether the resource has a link.
     *
     * @param $rel
     *
     * @return bool
     */
    public function hasLink($rel)
    {
        return isset($this->data['_links'][$rel]['href']);
    }

    /**
     * Get a link for a given resource relation.
     *
     * @param string $rel
     * @param bool   $absolute
     *
     * @return string
     */
    public function getLink($rel, $absolute = false)
    {
        if (!$this->hasLink($rel)) {
            throw new \InvalidArgumentException("Link not found: $rel");
        }
        $url = $this->data['_links'][$rel]['href'];
        if ($absolute && strpos($url, '//') === false) {
            // @todo there may be a more standard Guzzle way to combine URLs
            $base = parse_url($this->client->getBaseUrl());
            $url = $base['scheme'] . '://' . $base['host'] . $url;
        }
        return $url;
    }

    /**
     * Get a list of this resource's property names.
     *
     * @return string[]
     */
    public function getPropertyNames()
    {
        $keys = array_filter(array_keys($this->data), [$this, 'isProperty']);
        return $keys;
    }

    /**
     * Get an array of this resource's properties and their values.
     *
     * @return array
     */
    public function getProperties()
    {
        $keys = $this->getPropertyNames();
        return array_intersect_key($this->data, array_flip($keys));
    }

    /**
     * @param string $key
     *
     * @return bool
     */
    protected function isProperty($key)
    {
        return $key !== '_links' && $key !== '_embedded' && $key !== '_full';
    }
}
