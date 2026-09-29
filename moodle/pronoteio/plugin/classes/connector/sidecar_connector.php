<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace local_pronoteio\connector;

use local_pronoteio\local\account_manager;

/**
 * Connector talking to the Node.js sidecar (Pawnote) over signed REST calls.
 *
 * Signature: HMAC-SHA256(secret, timestamp . "\n" . nonce . "\n" . METHOD . "\n" . path?query . "\n" . sha256(body)),
 * sent in X-Pronoteio-Timestamp, X-Pronoteio-Nonce and X-Pronoteio-Signature. The random nonce keeps
 * two identical calls in the same second (a retry after a new login) from being refused as replays.
 * See sidecar/src/auth.ts.
 *
 * @package    local_pronoteio
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sidecar_connector implements connector_interface {

    /** @var \stdClass Linked account. */
    protected \stdClass $account;

    /** @var string Sidecar base URL without trailing slash. */
    protected string $baseurl;

    /** @var string Shared secret. */
    protected string $secret;

    /** @var int Timeout in seconds. */
    protected int $timeout;

    /** @var string|null Sidecar session identifier. */
    protected ?string $sessionid = null;

    /**
     * Constructor.
     *
     * @param \stdClass $account
     */
    public function __construct(\stdClass $account) {
        $this->account = $account;
        $this->baseurl = rtrim((string) get_config('local_pronoteio', 'sidecarurl'), '/');
        $this->secret = (string) get_config('local_pronoteio', 'sidecarsecret');
        $this->timeout = max(5, (int) get_config('local_pronoteio', 'timeout'));
    }

    #[\Override]
    public function get_name(): string {
        return 'sidecar';
    }

    #[\Override]
    public function supports(string $feature): bool {
        return true;
    }

    #[\Override]
    public function login(string $password = '', string $pin = ''): array {
        $payload = [
            'url' => $this->account->pronoteurl,
            'username' => $this->account->username,
            'deviceuuid' => $this->account->deviceuuid,
        ];
        if ($pin !== '') {
            $payload['pin'] = $pin;
        }
        if ($password !== '') {
            $payload['password'] = $password;
        } else {
            $token = account_manager::get_token($this->account);
            if ($token === '') {
                throw new connector_exception('error_session');
            }
            $payload['token'] = $token;
        }

        $response = $this->request('POST', '/session/login', [], $payload, false);
        if (empty($response['sessionid']) || !isset($response['token'])) {
            throw new connector_exception('error_session');
        }
        $this->sessionid = $response['sessionid'];
        account_manager::store_token($this->account, (string) $response['token']);
        account_manager::set_status($this->account, 'ok');

        return ['displayname' => (string) ($response['displayname'] ?? '')];
    }

    #[\Override]
    public function get_timetable(int $from, int $to): array {
        return $this->request('GET', '/timetable', ['from' => $from, 'to' => $to])['items'] ?? [];
    }

    #[\Override]
    public function get_homework(int $from, int $to): array {
        return $this->request('GET', '/homework', ['from' => $from, 'to' => $to])['items'] ?? [];
    }

    #[\Override]
    public function get_resources(): array {
        return $this->request('GET', '/resources')['items'] ?? [];
    }

    #[\Override]
    public function get_roster(string $resourceid): array {
        return $this->request('GET', '/roster', ['resource' => $resourceid])['items'] ?? [];
    }

    #[\Override]
    public function get_absences(string $resourceid, int $from, int $to): array {
        return $this->request('GET', '/absences', ['resource' => $resourceid, 'from' => $from, 'to' => $to])['items'] ?? [];
    }

    #[\Override]
    public function get_grades(string $resourceid): array {
        return $this->request('GET', '/grades', ['resource' => $resourceid])['items'] ?? [];
    }

    #[\Override]
    public function get_grade_context(): array {
        $response = $this->request('GET', '/grades/context');
        return [
            'periods' => $response['periods'] ?? [],
            'services' => $response['services'] ?? [],
            'maxScale' => isset($response['maxScale']) ? (float) $response['maxScale'] : null,
        ];
    }

    #[\Override]
    public function push_grades(string $serviceid, string $periodid, array $assessment, array $grades,
            bool $dryrun = false): array {
        if (!$dryrun && !get_config('local_pronoteio', 'enablegradewrite')) {
            throw new connector_exception('error_gradewritedisabled');
        }
        $response = $this->request('POST', '/grades', [], [
            'service' => $serviceid,
            'period' => $periodid,
            'assessment' => $assessment,
            'grades' => array_values($grades),
            'dryRun' => $dryrun,
        ]);
        return [
            'assessmentid' => isset($response['assessmentid']) ? (string) $response['assessmentid'] : null,
            'written' => (int) ($response['written'] ?? 0),
            'rejected' => $response['rejected'] ?? [],
            'dryRun' => (bool) ($response['dryRun'] ?? $dryrun),
        ];
    }

    /**
     * Performs a signed request, opening or reopening the session when needed.
     *
     * @param string $method GET or POST.
     * @param string $path
     * @param array $query
     * @param array|null $body
     * @param bool $needsession
     * @return array Decoded JSON.
     * @throws connector_exception
     */
    protected function request(string $method, string $path, array $query = [], ?array $body = null,
            bool $needsession = true): array {
        if ($this->baseurl === '' || $this->secret === '') {
            throw new connector_exception('error_sidecarconfig');
        }
        if ($needsession && $this->sessionid === null) {
            $this->login();
        }

        [$status, $data] = $this->send($method, $path, $query, $body, $needsession);
        if ($needsession && $status === 401 && ($data['error'] ?? '') === 'session_expired') {
            $this->sessionid = null;
            $this->login();
            [$status, $data] = $this->send($method, $path, $query, $body, true);
        }

        if ($status < 200 || $status >= 300) {
            if ($status === 401 && !$needsession) {
                account_manager::set_status($this->account, 'error');
            }
            $error = (string) ($data['error'] ?? ('HTTP ' . $status));
            if (str_starts_with($error, 'not_configured:')) {
                throw new connector_exception('error_notconfigured', substr($error, strlen('not_configured:')));
            }
            $loginerrors = [
                'bad_credentials' => 'error_badcredentials',
                'pin_required' => 'error_pinrequired',
                'bad_pin' => 'error_badpin',
                'security_setup_required' => 'error_securitysetup',
                'double_auth_required' => 'error_doubleauth',
                'service_not_in_period' => 'error_servicenotinperiod',
            ];
            if (isset($loginerrors[$error])) {
                throw new connector_exception($loginerrors[$error]);
            }
            throw new connector_exception('error_sidecar', $error);
        }
        return $data;
    }

    /**
     * Low level HTTP call.
     *
     * @param string $method
     * @param string $path
     * @param array $query
     * @param array|null $body
     * @param bool $withsession
     * @return array [int status, array data]
     * @throws connector_exception
     */
    protected function send(string $method, string $path, array $query, ?array $body, bool $withsession): array {
        $target = $path . ($query ? '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986) : '');
        $json = $body === null ? '' : json_encode($body, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(16));
        $signature = hash_hmac('sha256', implode("\n", [$timestamp, $nonce, $method, $target, hash('sha256', $json)]),
            $this->secret);

        $headers = [
            'Accept' => 'application/json',
            'X-Pronoteio-Timestamp' => $timestamp,
            'X-Pronoteio-Nonce' => $nonce,
            'X-Pronoteio-Signature' => $signature,
        ];
        if ($json !== '') {
            $headers['Content-Type'] = 'application/json';
        }
        if ($withsession && $this->sessionid !== null) {
            $headers['X-Pronoteio-Session'] = $this->sessionid;
        }

        // The sidecar listens on 127.0.0.1, which Moodle's cURL security helper blocks by default.
        $client = new \core\http_client(['timeout' => $this->timeout, 'ignoresecurity' => true]);
        try {
            $response = $client->request($method, $this->baseurl . $target, [
                'headers' => $headers,
                'body' => $json,
                'http_errors' => false,
            ]);
        } catch (\Throwable $e) {
            throw new connector_exception('error_sidecar', $e->getMessage());
        }

        $data = json_decode((string) $response->getBody(), true);
        return [$response->getStatusCode(), is_array($data) ? $data : []];
    }
}
