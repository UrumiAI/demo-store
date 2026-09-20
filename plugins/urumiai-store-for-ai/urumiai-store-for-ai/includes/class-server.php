<?php
/**
 * UrumiAI Store for AI — JSON-RPC 2.0 server core
 *
 * Framework-agnostic on purpose: handle() takes a decoded JSON-RPC array and
 * returns a response array. It knows nothing about WordPress, HTTP or
 * WooCommerce, which is what lets the whole surface be tested on a machine with
 * none of them installed. class-rest-controller.php is the only HTTP binding.
 *
 * Methods: initialize, ping, tools/list, tools/call, resources/list,
 * resources/templates/list, resources/read.
 *
 * ERROR MODEL, which matters for agent behavior:
 *   - Protocol faults (bad method, malformed params) are JSON-RPC errors.
 *   - Store and session refusals (out of stock, expired session, cap exceeded,
 *     capability disabled) are SUCCESSFUL responses carrying isError:true.
 * An agent should retry or re-plan on the second and give up on the first, so
 * conflating them would make a sold-out product look like a broken server.
 *
 * @package Urumiai_Store_For_Ai
 * @since 0.1.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

final class Urumiai_Store_For_Ai_Server {

    const PROTOCOL_VERSION = '2025-06-18';

    const ERROR_PARSE            = -32700;
    const ERROR_INVALID_REQUEST  = -32600;
    const ERROR_METHOD_NOT_FOUND = -32601;
    const ERROR_INVALID_PARAMS   = -32602;
    const ERROR_INTERNAL         = -32603;

    /**
     * Tool surface.
     *
     * @var Urumiai_Store_For_Ai_Tools
     */
    private $tools;

    /**
     * Resource surface.
     *
     * @var Urumiai_Store_For_Ai_Resources
     */
    private $resources;

    /**
     * Constructor.
     *
     * @param Urumiai_Store_For_Ai_Tools     $tools     Tool surface.
     * @param Urumiai_Store_For_Ai_Resources $resources Resource surface.
     */
    public function __construct(
        Urumiai_Store_For_Ai_Tools $tools,
        Urumiai_Store_For_Ai_Resources $resources
    ) {
        $this->tools     = $tools;
        $this->resources = $resources;
    }

    /**
     * Convenience factory wiring the default stack around an adapter.
     *
     * @param Urumiai_Store_For_Ai_Adapter       $adapter Store adapter.
     * @param Urumiai_Store_For_Ai_Session_Store $store   Session persistence.
     * @param array                              $config  Safety policy overrides.
     * @param callable|null                      $clock   Injectable clock.
     * @return Urumiai_Store_For_Ai_Server
     */
    public static function create(
        Urumiai_Store_For_Ai_Adapter $adapter,
        Urumiai_Store_For_Ai_Session_Store $store,
        array $config = array(),
        ?callable $clock = null
    ): Urumiai_Store_For_Ai_Server {
        $policy   = new Urumiai_Store_For_Ai_Safety_Policy($config);
        $sessions = new Urumiai_Store_For_Ai_Sessions($adapter, $store, $policy, $clock);

        return new self(
            new Urumiai_Store_For_Ai_Tools($adapter, $sessions, $policy),
            new Urumiai_Store_For_Ai_Resources($adapter, $sessions)
        );
    }

    /**
     * Handle one JSON-RPC request.
     *
     * @param array $request Decoded JSON-RPC request object.
     * @return array|null Response, or null for a notification (no id).
     */
    public function handle(array $request): ?array {
        $id              = array_key_exists('id', $request) ? $request['id'] : null;
        $is_notification = !array_key_exists('id', $request);

        if (!isset($request['jsonrpc']) || '2.0' !== $request['jsonrpc']) {
            return $is_notification
                ? null
                : $this->error($id, self::ERROR_INVALID_REQUEST, 'jsonrpc must be "2.0"');
        }
        if (!isset($request['method']) || !is_string($request['method'])) {
            return $is_notification
                ? null
                : $this->error($id, self::ERROR_INVALID_REQUEST, 'method is required');
        }

        $method = $request['method'];
        $params = isset($request['params']) && is_array($request['params']) ? $request['params'] : array();

        // Notifications get no response, whatever they asked for.
        if ($is_notification) {
            return null;
        }

        switch ($method) {
            case 'initialize':
                return $this->result($id, array(
                    'protocolVersion' => self::PROTOCOL_VERSION,
                    'capabilities'    => array(
                        'tools'     => array('listChanged' => false),
                        'resources' => array('subscribe' => false, 'listChanged' => false),
                    ),
                    'serverInfo'      => array(
                        'name'    => 'urumiai-store-for-ai',
                        'version' => defined('URUMIAI_STORE_FOR_AI_VERSION') ? URUMIAI_STORE_FOR_AI_VERSION : '0.0.0',
                    ),
                    'instructions'    => 'Call create_session first, then use its session_id for every cart tool. Browse the catalog, build the basket, and finish with create_checkout_handoff: it returns a single-use URL for the shopper to open, which loads the basket into their browser and shows checkout. Give them that URL and stop. This server cannot place an order or take payment.',
                ));

            case 'ping':
                return $this->result($id, array());

            case 'tools/list':
                return $this->result($id, array('tools' => $this->tools->public_definitions()));

            case 'tools/call':
                if (!isset($params['name']) || !is_string($params['name'])) {
                    return $this->error($id, self::ERROR_INVALID_PARAMS, 'params.name is required');
                }
                $arguments = isset($params['arguments']) && is_array($params['arguments'])
                    ? $params['arguments'] : array();
                return $this->result($id, $this->tools->call($params['name'], $arguments));

            case 'resources/list':
                return $this->result($id, array('resources' => $this->resources->definitions()));

            case 'resources/templates/list':
                return $this->result($id, array('resourceTemplates' => $this->resources->templates()));

            case 'resources/read':
                if (!isset($params['uri']) || !is_string($params['uri'])) {
                    return $this->error($id, self::ERROR_INVALID_PARAMS, 'params.uri is required');
                }
                $contents = $this->resources->read($params['uri']);
                if (null === $contents) {
                    return $this->error(
                        $id,
                        self::ERROR_INVALID_PARAMS,
                        sprintf('unknown resource "%s"', $params['uri'])
                    );
                }
                return $this->result($id, array('contents' => array($contents)));
        }

        return $this->error($id, self::ERROR_METHOD_NOT_FOUND, sprintf('unknown method "%s"', $method));
    }

    /**
     * Handle a raw JSON string, including batches.
     *
     * @param string $raw Raw request body.
     * @return array|null Response (or batch array), null when nothing to send.
     */
    public function handle_raw(string $raw) {
        $decoded = json_decode($raw, true);

        if (null === $decoded && JSON_ERROR_NONE !== json_last_error()) {
            return $this->error(null, self::ERROR_PARSE, 'parse error');
        }
        if (!is_array($decoded)) {
            return $this->error(null, self::ERROR_INVALID_REQUEST, 'request must be an object or array');
        }

        // A batch is a list; a single request is a map.
        $is_batch = array_keys($decoded) === range(0, count($decoded) - 1) && array() !== $decoded;
        if (!$is_batch) {
            return $this->handle($decoded);
        }

        $responses = array();
        foreach ($decoded as $entry) {
            if (!is_array($entry)) {
                $responses[] = $this->error(null, self::ERROR_INVALID_REQUEST, 'batch entry must be an object');
                continue;
            }
            $response = $this->handle($entry);
            if (null !== $response) {
                $responses[] = $response;
            }
        }

        return empty($responses) ? null : $responses;
    }

    /**
     * Build a success envelope.
     *
     * @param mixed $id     Request id.
     * @param array $result Result payload.
     * @return array
     */
    private function result($id, array $result): array {
        return array('jsonrpc' => '2.0', 'id' => $id, 'result' => $result);
    }

    /**
     * Build an error envelope.
     *
     * @param mixed  $id      Request id.
     * @param int    $code    JSON-RPC error code.
     * @param string $message Message.
     * @return array
     */
    private function error($id, int $code, string $message): array {
        return array(
            'jsonrpc' => '2.0',
            'id'      => $id,
            'error'   => array('code' => $code, 'message' => $message),
        );
    }
}
