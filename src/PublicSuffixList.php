<?php
/**
 * PHP Domain Parser: Public Suffix List based URL parsing.
 *
 * @see http://github.com/jeremykendall/php-domain-parser for the canonical source repository
 *
 * @copyright Copyright (c) 2017 Jeremy Kendall (http://jeremykendall.net)
 * @license   http://github.com/jeremykendall/php-domain-parser/blob/master/LICENSE MIT License
 */
declare(strict_types=1);

namespace Pdp;

use OutOfRangeException;

final class PublicSuffixList
{
    use LabelsTrait;

    const ALL_DOMAINS = 'ALL';
    const ICANN_DOMAINS = 'ICANN';
    const PRIVATE_DOMAINS = 'PRIVATE';

    /**
     * @var array
     */
    private $rules;

    /**
     * PublicSuffixList constructor.
     *
     * @param string $type  Tje PSL type 'ALL, ICANN or PRIVATE'
     * @param array  $rules
     */
    public function __construct(string $type, array $rules)
    {
        static $type_lists = [
            self::ALL_DOMAINS => 1,
            self::ICANN_DOMAINS => 1,
            self::PRIVATE_DOMAINS => 1,
        ];

        if (!isset($type_lists[$type])) {
            throw new OutOfRangeException(sprintf('Unknown PSL type %s', $type));
        }
        $this->type = $type;
        $this->rules = $rules;
    }

    /**
     * Tell whether the PSL resolves ICANN domains only.
     *
     * @return bool
     */
    public function isICANN()
    {
        return $this->type === self::ICANN_DOMAINS;
    }

    /**
     * Tell whether the PSL resolves private domains only.
     *
     * @return bool
     */
    public function isPrivate()
    {
        return $this->type === self::PRIVATE_DOMAINS;
    }

    /**
     * Tell whether the PSL resolves all domains.
     *
     * @return bool
     */
    public function isAll()
    {
        return $this->type === self::ALL_DOMAINS;
    }

    /**
     * Returns PSL public info for a given domain.
     *
     * @param string|null $domain
     *
     * @return Domain
     */
    public function query(string $domain = null): Domain
    {
        if (!$this->isMatchable($domain)) {
            return new NullDomain();
        }

        $normalizedDomain = $this->normalize($domain);
        $publicSuffix = $this->findPublicSuffix($this->getLabelsReverse($normalizedDomain));
        if ($publicSuffix === null) {
            return $this->handleNoMatches($domain);
        }

        return $this->handleMatches($domain, $publicSuffix);
    }

    /**
     * Tells whether the given domain is valid.
     *
     * @param string|null $domain
     *
     * @return bool
     */
    private function isMatchable($domain): bool
    {
        if ($domain === null) {
            return false;
        }

        if ($this->hasLeadingDot($domain)) {
            return false;
        }

        if ($this->isSingleLabelDomain($domain)) {
            return false;
        }

        if ($this->isIpAddress($domain)) {
            return false;
        }

        return true;
    }

    /**
     * Tells whether the domain starts with a dot character.
     *
     * @param string $domain
     *
     * @return bool
     */
    private function hasLeadingDot($domain): bool
    {
        return strpos($domain, '.') === 0;
    }

    /**
     * Tells whether the submitted domain is an IP address.
     *
     * @param string $domain
     *
     * @return bool
     */
    private function isIpAddress(string $domain): bool
    {
        return filter_var($domain, FILTER_VALIDATE_IP) !== false;
    }

    /**
     * Normalize domain.
     *
     * "The domain must be canonicalized in the normal way for hostnames - lower-case, Punycode."
     *
     * @see http://www.ietf.org/rfc/rfc3492.txt
     *
     * @param string $domain
     *
     * @return string
     */
    private function normalize(string $domain): string
    {
        return strtolower(idn_to_ascii($domain, 0, INTL_IDNA_VARIANT_UTS46));
    }

    /**
     * Returns the matched public suffix or null
     * if none found.
     *
     * @param array $labels
     *
     * @return string|null
     */
    private function findPublicSuffix(array $labels)
    {
        $matches = [];
        $rules = $this->rules;
        foreach ($labels as $label) {
            if ($this->isExceptionRule($label, $rules)) {
                break;
            }

            if ($this->isWildcardRule($rules)) {
                array_unshift($matches, $label);
                break;
            }

            if (!$this->matchExists($label, $rules)) {
                // Avoids improper parsing when $domain's subdomain + public suffix ===
                // a valid public suffix (e.g. domain 'us.example.com' and public suffix 'us.com')
                //
                // Added by @goodhabit in https://github.com/jeremykendall/php-domain-parser/pull/15
                // Resolves https://github.com/jeremykendall/php-domain-parser/issues/16
                break;
            }

            array_unshift($matches, $label);
            $rules = $rules[$label];
        }

        return empty($matches) ? null : implode('.', array_filter($matches, 'strlen'));
    }

    /**
     * Tells whether a PSL exception rule is found.
     *
     * @param string $label
     * @param array  $rules
     *
     * @return bool
     */
    private function isExceptionRule(string $label, array $rules): bool
    {
        return isset($rules[$label], $rules[$label]['!']);
    }

    /**
     * Tells whether a PSL wildcard rule is found.
     *
     * @param array $rules
     *
     * @return bool
     */
    private function isWildcardRule(array $rules): bool
    {
        return isset($rules['*']);
    }

    /**
     * Tells whether a PSL label matches the given domain label.
     *
     * @param string $label
     * @param array  $rules
     *
     * @return bool
     */
    private function matchExists(string $label, array $rules): bool
    {
        return isset($rules[$label]);
    }

    /**
     * Returns the MatchedDomain value object.
     *
     * @param string $domain
     * @param string $publicSuffix
     *
     * @return MatchedDomain
     */
    private function handleMatches(string $domain, string $publicSuffix): MatchedDomain
    {
        if (!$this->isPunycoded($domain)) {
            $publicSuffix = idn_to_utf8($publicSuffix, 0, INTL_IDNA_VARIANT_UTS46);
        }

        return new MatchedDomain($domain, $publicSuffix);
    }

    /**
     * Tells whether the domain is punycoded.
     *
     * @param string $domain
     *
     * @return bool
     */
    private function isPunycoded(string $domain): bool
    {
        return strpos($domain, 'xn--') !== false;
    }

    /**
     * Returns the UnmatchedDomain value object.
     *
     * @param string $domain
     *
     * @return UnmatchedDomain
     */
    private function handleNoMatches(string $domain): UnmatchedDomain
    {
        $labels = $this->getLabels($domain);
        $publicSuffix = array_pop($labels);
        if (!$this->isPunycoded($domain) && $publicSuffix !== null) {
            $publicSuffix = idn_to_utf8($publicSuffix, 0, INTL_IDNA_VARIANT_UTS46);
        }

        return new UnmatchedDomain($domain, $publicSuffix);
    }
}
