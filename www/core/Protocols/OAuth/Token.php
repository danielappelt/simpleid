<?php
/*
 * SimpleID
 *
 * Copyright (C) Kelvin Mo 2014-2022
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public
 * License as published by the Free Software Foundation; either
 * version 2 of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU
 * General Public License for more details.
 *
 * You should have received a copy of the GNU General Public
 * License along with this program; if not, write to the Free
 * Software Foundation, Inc., 675 Mass Ave, Cambridge, MA 02139, USA.
 */

namespace SimpleID\Protocols\OAuth;

use Branca\Branca;
use SimpleID\Crypt\Random;
use SimpleID\Store\StoreManager;

/**
 * An OAuth access or refresh token.
 *
 * Tokens generated by this class are *hybrid* tokens.  That is, the encoded token string
 * (which is returned to the client) contains encrypted basic data on the authorisation
 * and scope associated with the token.  Additional data may also be stored on the server side.
 * Therefore a resource server, with the appropriate keys, can decrypt the encoded token without
 * making further calls to the SimpleID server.
 *
 * This class cannot be instantiated directly.  It can only be created by its subclasses.
 */
class Token {
    /** The separator between the token ID and the source reference */
    const SOURCE_REF_SEPARATOR = '~';

    /** Denotes a token without an expiry time */
    const TTL_PERPETUAL = 0;

    const KEY_FQAID = 'a';
    const KEY_CACHE_HASH = 'h';
    const KEY_ID = 'i';
    const KEY_SOURCEREF = 'r';
    const KEY_SCOPEREF = 's';
    const KEY_EXPIRE = 'x';

    /** @var Branca the branca token generator */
    protected $branca;

    /** @var string the unique ID of this token */
    protected $id;

    /** @var Authorization the authorisation */
    protected $authorization;

    /** @var array */
    protected $scope;

    /** @var int|null the expiry time */
    protected $expire = NULL;

    /** @var string|null the source reference (a reference to the authorization code or refresh token) */
    protected $source_ref = NULL;

    /** @var array additional data to be stored on the server in relation to the token */
    protected $additional = [];

    /** @var string|null the encoded token */
    protected $encoded = NULL;

    /** @var bool whether the token has been parsed properly */
    protected $is_parsed = false;

    /** Creates a token */
    protected function __construct() {
        $this->branca = new Branca(base64_decode(strtr(self::getKey(), '-_', '+/')));
    }

    /**
     * Initialises a token.
     *
     * @param Authorization $authorization the underlying authorisation
     * @param array|string $scope the scope of the token
     * @param int $expires_in the validity of the token, in seconds, or
     * {@link TTL_PERPETUAL}
     * @param TokenSource $source the token source
     * @param array $additional additional data to be stored on the
     * server
     */
    protected function init($authorization, $scope = [], $expires_in = self::TTL_PERPETUAL, $source = NULL, $additional = []) {
        $rand = new Random();

        $this->id = $rand->id();
        $this->authorization = $authorization;
        if (count($scope) == 0) {
            $this->scope = $authorization->getScope();
        } else {
            $this->scope = $authorization->filterScope($scope);
        }

        if ($source != null) $this->source_ref = $source->getSourceRef();
        if ($expires_in > 0) $this->expire = time() + $expires_in;
        $this->additional = $additional;
    }

    /**
     * Returns whether the token is valid.
     *
     * A token is valid if it is successfully created or parsed, and
     * is not expired (if the token has an expiry date).
     *
     * Note that a valid token be still not provide sufficient authority
     * to access protected resources.  You will also need to check
     * the token's scope using the {@link hasScope()} method.
     *
     * @return bool true if the token is valid
     */
    public function isValid() {
        if (!$this->is_parsed) return false;
        if ($this->expire != null) return !$this->hasExpired();
        return true;
    }

    /**
     * Returns the unique ID for this token.
     *
     * @return string the ID
     */
    public function getID() {
        return $this->id;
    }

    /**
     * Returns the authorisation that created this token.
     *
     * @return Authorization the authorisation object
     */
    public function getAuthorization() {
        return $this->authorization;
    }

    /**
     * Returns the scope covered by the token
     *
     * @return array the scope
     */
    public function getScope() {
        return $this->scope;
    }

    /**
     * Checks whether the token covers a specified scope.
     *
     * This method will return true if the token covers *all* of the
     * scope specified by `$scope`.
     *
     * @param string|array $scope the scope to test
     * @return bool true if the token covers all of the specified
     * scope
     */
    public function hasScope($scope) {
        if (!is_array($scope)) $scope = explode(' ', $scope);
        return (count(array_diff($scope, $this->scope)) == 0);
    }

    /**
     * Returns additional data stored on the server for this token
     *
     * @return array the additional data
     */
    public function getAdditionalData() {
        return $this->additional;
    }

    /**
     * Checks whether the token has expired.  If the token has no expiry date,
     * this function will always return `false`.
     *
     * @return bool true if the token has expired
     */
    public function hasExpired() {
        if ($this->expire == null) return false;
        return (time() >= $this->expire);
    }

    /**
     * Returns the encoded token as a string.
     *
     * @return string the encoded token
     */
    public function getEncoded() {
        return $this->encoded;
    }

    /**
     * Revokes a token
     */
    public function revoke() {
        $cache = \Cache::instance();
        $cache->clear($this->getCacheKey());
    }

    /**
     * Revokes all tokens issued from a specifed authorisation and,
     * optionally, a token source.
     *
     * @param Authorization $authorization the authorisation for which
     * tokens are to be revoked
     * @param TokenSource|string $source if specified, only delete tokens issued
     * from this source
     */
    public static function revokeAll($authorization, $source = null) {
        $cache = \Cache::instance();

        if ($source != null) {
            if ($source instanceof TokenSource) {
                $source_ref = $source->getSourceRef();
            } elseif (is_string($source)) {
                $source_ref = $source;
            }
            $suffix = self::SOURCE_REF_SEPARATOR . $source_ref;
        } else {
            $suffix = ''; 
        }
        
        $suffix .= '.' . $authorization->getFullyQualifiedID() . '.oauth_token';
        $cache->reset($suffix);
    }

    /**
     * Returns the key used to store data for this token in the FatFree cache
     *
     * @return string the key
     */
    protected function getCacheKey() {
        $key = $this->id;
        if ($this->source_ref != NULL) {
            $key .= self::SOURCE_REF_SEPARATOR . $this->source_ref;
        }
        $key .= '.' . $this->authorization->getFullyQualifiedID() . '.oauth_token';
        return $key;
    }

    /**
     * Parses an encoded token
     */
    protected function parse() {
        $store = StoreManager::instance();
        $cache = \Cache::instance();

        try {
            $message = $this->branca->decode($this->encoded);
            $token_data = json_decode($message, true);

            $this->id = $token_data[self::KEY_ID];
            list($auth_state, $aid) = explode('.', $token_data[self::KEY_FQAID]);
            $this->scope = $this->resolveScope($token_data[self::KEY_SCOPEREF]);
            if (isset($token_data[self::KEY_EXPIRE])) $this->expire = $token_data[self::KEY_EXPIRE];
            if (isset($token_data[self::KEY_SOURCEREF])) $this->source_ref = $token_data[self::KEY_SOURCEREF];

            /** @var Authorization $authorization */
            $authorization = $store->loadAuth($aid);
            $this->authorization = $authorization;
            if ($this->authorization == NULL) return;
            if ($this->authorization->getAuthState() != $auth_state) return;

            $server_data = $cache->get($this->getCacheKey());
            if ($server_data === false) return;
            if (base64_encode(hash('sha256', serialize($server_data), true)) !== $token_data[self::KEY_CACHE_HASH]) return;
            $this->additional = $server_data['additional'];

            $this->is_parsed = true;            
        } catch (\RuntimeException $e) {
            return null;
        }
    }

    /**
     * Encodes a token.
     *
     * @param array $server_data data to be stored on the server side
     * @param array $token_data data to be encoded in the token
     *
     */
    protected function encode($server_data = [], $token_data = []) {
        $cache = \Cache::instance();
        
        $fqaid = $this->authorization->getFullyQualifiedID();

        $server_data = array_merge([
            'id' => $this->id,
            'fqaid' => $fqaid,
            'scope' => $this->scope,
            'additional' => $this->additional
        ], $server_data);
        $token_data = array_merge([
            self::KEY_ID => $server_data['id'],
            self::KEY_FQAID => $server_data['fqaid'],
            self::KEY_SCOPEREF => $this->getScopeRef($this->scope),
        ], $token_data);

        if ($this->expire != NULL) {
            $server_data['expire'] = $this->expire;
            $token_data[self::KEY_EXPIRE] = $this->expire;
        }

        if ($this->source_ref != NULL) {
            $server_data['source_ref'] = $this->source_ref;
            $token_data[self::KEY_SOURCEREF] = $this->source_ref;
        }

        $cache->set($this->getCacheKey(), $server_data, ($this->expire != NULL) ? $this->expire - time() : 0);
        $token_data[self::KEY_CACHE_HASH] = base64_encode(hash('sha256', serialize($server_data), true));

        $this->encoded = $this->branca->encode(json_encode($token_data));
    }

    /**
     * Compresses a scope string.
     *
     * Each SimpleID installation compiles a mapping of all the known scopes.
     * This function compresses a scope string by replacing the individual
     * scope items with a reference to this map.
     *
     * @param array $scope the scope to compress
     * @return string the compressed scope reference
     */
    protected function getScopeRef($scope) {
        $ref = [];

        $store = StoreManager::instance();
        $scope_map = $store->getSetting('oauth_scope', []);

        foreach ($scope as $item) {
            $i = array_search($item, $scope_map);
            if ($i === false) {
                $scope_map[] = $item;
                $i = count($scope_map) - 1;
            }
            $ref[] = '\\' . $i;
        }

        $store->setSetting('oauth_scope', $scope_map);
        return implode(' ', $ref);
    }

    /**
     * Resolves a compressed scope reference.
     *
     * This function is the reverse of {@link getScopeRef()}.
     *
     * @param string $ref the compressed scope reference
     * @return array<string> array of scope items
     */
    protected function resolveScope($ref) {
        $scope = [];

        $store = StoreManager::instance();
        $scope_map = $store->getSetting('oauth_scope', []);

        $refs = explode(' ', $ref);
        foreach ($refs as $item) {
            if (preg_match('/\\\\(\d+)/', $item, $matches)) {
                $scope[] = $scope_map[$matches[1]];
            }
        }

        return $scope;
    }

    /**
     * Returns the current scope map used in the {@link getScopeRef()} and
     * {@link resolveScope()} functions.
     *
     * @return array the scope map
     */
    static function getScopeRefMap() {
        $store = StoreManager::instance();
        return $store->getSetting('oauth_scope', []);
    }

    /**
     * Gets the site-specific encryption and signing key.
     *
     * If the key does not exist, it is automatically generated.
     *
     * @return string the site-specific encryption and signing key
     * as a base64url encoded string
     */
    static protected function getKey() {
        $store = StoreManager::instance();

        $key = $store->getSetting('oauth-token');

        if ($key == NULL) {
            $rand = new Random();

            $key = strtr(base64_encode($rand->bytes(32)), '+/', '-_');
            $store->setSetting('oauth-token', $key);
        }

        return $key;
    }
}

?>