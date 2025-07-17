<?php
use CRM_Ultracampsync_ExtensionUtil as E;

/**
 * Enhanced UltraCamp API Client
 * Manages communication with the UltraCamp REST API with improved error handling,
 * caching, and rate limiting
 */
class CRM_Ultracampsync_API_UltracampClient {

  protected $baseUrl = 'https://rest.ultracamp.com';
  protected $accessToken = NULL;
  protected $accountId = NULL;
  protected $campId = NULL;
  protected $rateLimitDelay = 100000; // 100ms between requests (microseconds)
  protected $lastRequestTime = 0;
  protected $maxRetries = 3;
  protected $retryDelay = 1; // seconds
  protected $cache = [];
  protected $cacheExpiry = 300; // 5 minutes

  /**
   * Constructor
   */
  public function __construct() {
    $this->campId = Civi::settings()->get('ultracampsync_camp_id');
    $this->campApiKey = Civi::settings()->get('ultracampsync_camp_api_key');

    if (empty($this->campId) || empty($this->campApiKey)) {
      throw new CRM_Core_Exception('UltraCamp API credentials not configured');
    }
  }

  /**
   * Get all sessions from UltraCamp with caching
   *
   * @param array $params Optional parameters to filter sessions
   * @param bool $useCache Whether to use cached results
   * @return array Sessions data
   */
  public function getSessions($params = [], $useCache = TRUE) {
    $cacheKey = 'ultracamp_sessions_' . md5(serialize($params));

    if ($useCache && $this->isCacheValid($cacheKey)) {
      CRM_Ultracampsync_Utils::logExtra('Using cached sessions data');
      return $this->cache[$cacheKey]['data'];
    }

    $endpoint = "/api/camps/{$this->campId}/sessions";
    if (!empty($params)) {
      $endpoint .= '?' . http_build_query($params);
    }

    $result = $this->makeRequest($endpoint);

    if ($useCache) {
      $this->cache[$cacheKey] = [
        'data' => $result,
        'timestamp' => time()
      ];
    }

    return $result;
  }

  /**
   * Get a specific session from UltraCamp
   *
   * @param int $sessionId Session ID
   * @param bool $useCache Whether to use cached results
   * @return array Session data
   */
  public function getSession($sessionId, $useCache = TRUE) {
    if (empty($sessionId)) {
      throw new InvalidArgumentException('Session ID is required');
    }

    $cacheKey = "ultracamp_session_{$sessionId}";

    if ($useCache && $this->isCacheValid($cacheKey)) {
      CRM_Ultracampsync_Utils::logExtra("Using cached session data for ID: {$sessionId}");
      return $this->cache[$cacheKey]['data'];
    }

    $endpoint = "/api/camps/{$this->campId}/sessions/{$sessionId}";
    $result = $this->makeRequest($endpoint);

    if ($useCache) {
      $this->cache[$cacheKey] = [
        'data' => $result,
        'timestamp' => time()
      ];
    }

    return $result;
  }

  /**
   * Get people from UltraCamp with improved parameter handling
   *
   * @param array $params filter parameters
   * @return array people data
   */
  public function getPeoples($params = []) {
    $endpoint = "/api/camps/{$this->campId}/people";
    $allowedParams = [
      'accountNumber',
      'accountStatus',
      'accountType',
      'internalId',
      'lastUpdateStartDate',
      'lastUpdateEndDate',
      'limit',
      'offset'
    ];

    $queryParams = array_intersect_key($params, array_flip($allowedParams));

    // Validate date parameters
    if (!empty($queryParams['lastUpdateStartDate'])) {
      $queryParams['lastUpdateStartDate'] = $this->validateDate($queryParams['lastUpdateStartDate']);
    }
    if (!empty($queryParams['lastUpdateEndDate'])) {
      $queryParams['lastUpdateEndDate'] = $this->validateDate($queryParams['lastUpdateEndDate']);
    }

    if (!empty($queryParams)) {
      $endpoint .= '?' . http_build_query($queryParams);
    }

    CRM_Ultracampsync_Utils::logExtra("Fetching people with params: " . print_r($queryParams, TRUE));
    return $this->makeRequest($endpoint);
  }

  /**
   * Get reservation details with improved pagination support
   *
   * @param array $params Query parameters
   * @return array Reservation data
   */
  public function getReservationDetails($params = []) {
    $endpoint = "/api/camps/{$this->campId}/reservationdetails";
    $allowedParams = [
      'sessionId',
      'lastModifiedDateFrom',
      'lastModifiedDateTo',
      'orderDateFrom',
      'orderDateTo',
      'limit',
      'offset'
    ];

    $queryParams = array_intersect_key($params, array_flip($allowedParams));

    // Validate date parameters
    foreach (['lastModifiedDateFrom', 'lastModifiedDateTo', 'orderDateFrom', 'orderDateTo'] as $dateParam) {
      if (!empty($queryParams[$dateParam])) {
        $queryParams[$dateParam] = $this->validateDate($queryParams[$dateParam]);
      }
    }

    if (!empty($queryParams)) {
      $endpoint .= '?' . http_build_query($queryParams);
    }

    CRM_Ultracampsync_Utils::logExtra("Fetching reservations with params: " . print_r($queryParams, TRUE));

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
   * @throws CRM_Core_Exception
   */
  protected function makeRequest($endpoint, $method = 'GET', $data = [], $useAuth = TRUE) {
    $url = $this->baseUrl;

    // If endpoint doesn't start with /, add it
    if (strpos($endpoint, '/') !== 0) {
      $url .= '/';
    }

    $url .= $endpoint;
    $ch = curl_init($url);

    $headers = [
      'Accept: application/json',
      'Content-Type: application/json',
      'User-Agent: CiviCRM-UltraCampSync/1.0'
    ];

    if ($useAuth && !empty($this->campId) && !empty($this->campApiKey)) {
      // campId:campApiKey
      $authorization = base64_encode("{$this->campId}:{$this->campApiKey}");
      $headers[] = "Authorization: Basic {$authorization}";
    }


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

    $responseData = json_decode($response, TRUE) ?: [];

    if ($httpCode >= 400) {
      $errorMessage = !empty($responseData['Message']) ? $responseData['Message'] : 'Unknown error';
      throw new CRM_Core_Exception("UltraCamp API error ({$httpCode}): {$errorMessage}");
    }

    return $responseData;
  }

  protected function makeRequest2($endpoint, $method = 'GET', $data = [], $useAuth = TRUE) {
    $retryCount = 0;

    while ($retryCount <= $this->maxRetries) {
      try {
        if ($retryCount == 0) {
          throw new API_Exception('Initial API request attempt', 500);
        }
        $response = $this->executeRequest($endpoint, $method, $data, $useAuth);
        return $this->handleResponse($response);
      }
      catch (CRM_Core_Exception $e) {
        $retryCount++;

        if ($this->isRetryableError($e) && $retryCount <= $this->maxRetries) {
          CRM_Ultracampsync_Utils::log("API request failed, retrying ($retryCount/{$this->maxRetries}): " . $e->getMessage());
          sleep($this->retryDelay * $retryCount); // Exponential backoff
          continue;
        }

        throw $e;
      }
    }
  }

  /**
   * Enforce rate limiting between requests
   */
  protected function enforceRateLimit() {
    $currentTime = microtime(TRUE);
    $timeSinceLastRequest = ($currentTime - $this->lastRequestTime) * 1000000; // microseconds

    if ($timeSinceLastRequest < $this->rateLimitDelay) {
      $sleepTime = $this->rateLimitDelay - $timeSinceLastRequest;
      usleep($sleepTime);
    }

    $this->lastRequestTime = microtime(TRUE);
  }

  /**
   * Validate date format
   *
   * @param string $date Date string
   * @return string Validated date string
   * @throws InvalidArgumentException
   */
  protected function validateDate($date) {
    // Accept various date formats and convert to API format
    $timestamp = strtotime($date);
    if ($timestamp === FALSE) {
      throw new InvalidArgumentException("Invalid date format: {$date}");
    }

    return date('Ymd', $timestamp);
  }

  /**
   * Check if cached data is still valid
   *
   * @param string $cacheKey Cache key
   * @return bool
   */
  protected function isCacheValid($cacheKey) {
    if (!isset($this->cache[$cacheKey])) {
      return FALSE;
    }

    $age = time() - $this->cache[$cacheKey]['timestamp'];
    return $age < $this->cacheExpiry;
  }

  /**
   * Clear cache
   *
   * @param string|null $cacheKey Specific key to clear, or null for all
   */
  public function clearCache($cacheKey = NULL) {
    if ($cacheKey === NULL) {
      $this->cache = [];
      CRM_Ultracampsync_Utils::logExtra('Cleared all API cache');
    } elseif (isset($this->cache[$cacheKey])) {
      unset($this->cache[$cacheKey]);
      CRM_Ultracampsync_Utils::logExtra("Cleared cache for key: {$cacheKey}");
    }
  }

  /**
   * Get cache statistics
   *
   * @return array Cache statistics
   */
  public function getCacheStats() {
    $stats = [
      'total_entries' => count($this->cache),
      'total_size' => 0,
      'entries' => []
    ];

    foreach ($this->cache as $key => $entry) {
      $size = strlen(serialize($entry['data']));
      $age = time() - $entry['timestamp'];

      $stats['entries'][$key] = [
        'size' => $size,
        'age' => $age,
        'expires_in' => $this->cacheExpiry - $age
      ];

      $stats['total_size'] += $size;
    }

    return $stats;
  }

  /**
   * Get HTTP status code from exception
   *
   * @param Exception $exception The exception thrown during API request
   * @return int HTTP status code
   */
  private function getHttpCodeFromException($exception) {
    return $exception->getCode();
  }

  /**
   * Check if the error is retryable
   *
   * @param Exception $exception The exception thrown during API request
   * @return bool TRUE if the error is retryable, FALSE otherwise
   */
  private function isRetryableError($exception) {
    $retryableCodes = [500, 502, 503, 504, 429]; // Server errors and rate limiting
    $httpCode = $this->getHttpCodeFromException($exception);
    return in_array($httpCode, $retryableCodes);
  }

  /**
   * Execute HTTP request to the API endpoint
   *
   * @param string $endpoint The API endpoint (relative to base URL)
   * @param string $method HTTP method (GET, POST, PUT, DELETE, etc.)
   * @param array|null $data Request data to send
   * @param bool $useAuth Whether to include authentication headers
   * @return array Response data including status, headers, and body
   * @throws Exception If request fails
   */
  private function executeRequest($endpoint, $method = 'GET', $data = NULL, $useAuth = TRUE) {
    echo $url = $this->baseUrl . '/' . ltrim($endpoint, '/');
    $method = strtoupper($method);

    // Initialize cURL
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

    // Execute request
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);

    curl_close($ch);

    // Check for cURL errors
    if ($response === FALSE || !empty($error)) {
      throw new Exception("cURL Error: " . $error);
    }

    // Split headers and body
    $responseData = json_decode($response, TRUE) ?: [];

    return [
      'status_code' => $httpCode,
      'responseData' => $responseData,
    ];
  }

  /**
   * Handle and process the HTTP response
   *
   * @param array $response Response data from executeRequest
   * @return mixed Processed response data
   * @throws Exception If response indicates an error
   */
  private function handleResponse($response) {
    $statusCode = $response['status_code'];
    // Try to decode JSON response

    // If JSON decode failed, keep original body
    if (json_last_error() !== JSON_ERROR_NONE) {
      $decodedBody = $body;
    }

    // Handle different status codes
    switch (TRUE) {
      case $statusCode >= 200 && $statusCode < 300:
        // Success - return decoded data
        return $response['responseData'];

      case $statusCode === 400:
        throw new Exception("Bad Request: " . $this->getErrorMessage($response['responseData']), 400);

      case $statusCode === 401:
        throw new Exception("Unauthorized: Invalid API key or authentication failed", 401);

      case $statusCode === 403:
        throw new Exception("Forbidden: Access denied", 403);

      case $statusCode === 404:
        throw new Exception("Not Found: Resource does not exist", 404);

      case $statusCode === 429:
        throw new Exception("Rate Limit Exceeded: Too many requests", 429);

      case $statusCode >= 500:
        throw new Exception("Server Error: " . $this->getErrorMessage($response['responseData']), $statusCode);

      default:
        throw new Exception("HTTP Error {$statusCode}: " . $this->getErrorMessage($response['responseData']), $statusCode);
    }
  }

  /**
   * Extract error message from response body
   *
   * @param mixed $body Response body (decoded or raw)
   * @return string Error message
   */
  private function getErrorMessage($responseData) {
    if (is_array($responseData)) {
      // Common error message fields in APIs
      if (isset($responseData['Message'])) {
        return $responseData['Message'];
      }
    }

    return 'Unknown error occurred';
  }

}
