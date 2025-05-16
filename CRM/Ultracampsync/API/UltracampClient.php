<?php
use CRM_UltracampSync_ExtensionUtil as E;

/**
 * UltraCamp API Client
 * Manages communication with the UltraCamp REST API
 */
class CRM_UltracampSync_API_UltracampClient {

  protected $baseUrl = 'https://rest.ultracamp.com';
  protected $accessToken = NULL;
  protected $accountId = NULL;
  protected $campId = NULL;
  protected $clientSecret = NULL;

  /**
   * Constructor
   */
  public function __construct() {
    $this->campId = Civi::settings()->get('ultracampsync_camp_id');
    $this->campApiKey = Civi::settings()->get('ultracampsync_camp_api_key');
  }

  /**
   * Get all sessions from UltraCamp
   *
   * @param array $params Optional parameters to filter sessions
   * @return array Sessions data
   */
  public function getSessions($params = []) {
    $endpoint = "/api/camps/{$this->campId}/sessions";
    return $this->makeRequest($endpoint);
  }

  /**
   * Get a specific session from UltraCamp
   *
   * @param int $sessionId Session ID
   * @return array Session data
   */
  public function getSession($sessionId) {
    $endpoint = "/api/camps/{$this->campId}/sessions/{$sessionID}";
    return $this->makeRequest($endpoint);
  }

  /**
   * Get all Reservation from UltraCamp
   *
   * @return array Reservation data
   */
  public function getReservationDetails($params = []) {
    $endpoint = "/api/camps/{$this->campId}/reservationdetails";
    $queryParams = [];

    if (!empty($params['sessionId'])) {
      $queryParams['sessionId'] = $params['sessionId'];
    }
    if (!empty($params['lastModifiedDateFrom'])) {
      $queryParams['lastModifiedDateFrom'] = $params['lastModifiedDateFrom'];
    }
    if (!empty($params['lastModifiedDateTo'])) {
      $queryParams['lastModifiedDateTo'] = $params['lastModifiedDateTo'];
    }
    if (!empty($params['orderDateFrom'])) {
      $queryParams['orderDateFrom'] = $params['orderDateFrom'];
    }
    if (!empty($params['orderDateTo'])) {
      $queryParams['orderDateTo'] = $params['orderDateTo'];
    }

    if (!empty($queryParams)) {
      $endpoint .= '?' . http_build_query($queryParams);
    }
    return $this->makeRequest($endpoint);
  }

  /**
   * Make an HTTP request to the UltraCamp API
   *
   * @param string $endpoint API endpoint
   * @param string $method HTTP method (GET, POST, etc.)
   * @param array $data Request data
   * @param bool $useAuth Whether to use authentication
   * @return array Response data
   */
  protected function makeRequest($endpoint, $method = 'GET', $data = [], $useAuth = TRUE) {
    $url = $this->baseUrl;

    // If endpoint doesn't start with /, add it
    if (strpos($endpoint, '/') !== 0) {
      $url .= '/';
    }

    $url .= $endpoint;
    echo $url;
    $ch = curl_init($url);

    $headers = [
      'Accept: application/json',
      'Content-Type: application/json',
    ];

    if ($useAuth && !empty($this->campId) && !empty($this->campApiKey)) {
      // campId:campApiKey
      $authorization = base64_encode("{$this->campId}:{$this->campApiKey}");
      $headers[] = "Authorization: Basic {$authorization}";
    }
    echo '<pre>$this'; print_r($this); echo '</pre>';
    echo '<pre>'; print_r($headers); echo '</pre>';


    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);

    if ($method === 'POST') {
      curl_setopt($ch, CURLOPT_POST, TRUE);
      curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }
    elseif ($method !== 'GET') {
      curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
      if (!empty($data)) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
      }
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);

    curl_close($ch);

    if ($error) {
      throw new CRM_Core_Exception("UltraCamp API request failed: {$error}");
    }

    $responseData = json_decode($response, TRUE);

    if ($httpCode >= 400) {
      $errorMessage = !empty($responseData['error']) ? $responseData['error'] : 'Unknown error';
      throw new CRM_Core_Exception("UltraCamp API error ({$httpCode}): {$errorMessage}");
    }

    return $responseData;
  }

}
