<?php

/**
 * Description of ZimbraAdmin
 *
 * @author LiberSoft <info@libersoft.it>
 */
class ZimbraAdmin
{

    private $authToken;
    private $zimbraConnect;

    public function __construct($server, $port = 7071, $authToken = null)
    {
        $this->zimbraConnect = new ZimbraSOAP($server, $port);

        if ($authToken) {
            $this->authToken = $authToken;
            $this->zimbraConnect->addContextChild('authToken', $this->authToken);
        }
    }

    public function auth($email, $password)
    {
        $SOAPMessage = $this->zimbraConnect;
        $xml = $SOAPMessage->request('AuthRequest', null, 'urn:zimbraAdmin', array('name' => $email, 'password' => $password));

        $this->authToken = $xml->children()->AuthResponse->authToken;
        $this->zimbraConnect->addContextChild('authToken', $this->authToken);

        return (string) $this->authToken;
    }

    public function searchDirectory($domain, $limit = 10, $offset = 0, $type = 'accounts', $sort = null, $query = null)
    {
        if (sfConfig::get('app_account_hide_system_users', false) && $type == 'accounts') {
            $ldapQuery = "&amp;(";
            if ($query !== null) {
                $ldapQuery .= "(name=$query*)";
            }
            foreach (sfConfig::get('app_account_system_users_regexp') as $account) {
                $ldapQuery .= "(!(name=$account))";
            }
            $ldapQuery .= ')';
        } else {
            $ldapQuery = '';
        }

        $attributes = array(
            'limit'  => $limit,
            'offset' => $offset,
            'domain' => $domain,
            'types'  => $type,
        );

        if (!is_null($sort)) {
            $attributes['sortBy'] = $sort[0];
            $attributes['sortAscending'] = $sort[1];
        }

        return $this->zimbraConnect->request('SearchDirectoryRequest', null, 'urn:zimbraAdmin', $attributes, array('query' => $ldapQuery));
    }

    public function getAccounts(array $attributes, $sort = null, $query = null)
    {
        $accounts = $this->searchDirectory($attributes['domain'], $attributes['limit'], $attributes['offset'], 'accounts', $sort, $query);

        $results = array();

        foreach ($accounts->children()->SearchDirectoryResponse->children() as $account) {
            $results[] = new Account($account);
        }

        return $results;
    }

    public function count($domain, $query = null)
    {
        $response = $this->searchDirectory($domain, 1, 0, 'accounts', null, $query);

        return $response->children()->SearchDirectoryResponse['searchTotal'];
    }

    public function deleteAccount($id)
    {
        $this->zimbraConnect->request('DeleteAccountRequest', null, 'urn:zimbraAdmin', array(), array('id' => $id));

        return true;
    }

    public function setPassword($id, $password)
    {
        $params = array(
            'id' => $id,
            'newPassword' => $password,
        );
        $this->zimbraConnect->request('SetPasswordRequest', null, 'urn:zimbraAdmin', array(), $params);
    }

    private function getQuotaUsage(array $attributes, $targetServer = null)
    {
        if (isset($targetServer)) {
            $this->zimbraConnect->addContextChild('targetServer', $targetServer);
        }

        return $this->zimbraConnect->request('GetQuotaUsageRequest', null, 'urn:zimbraAdmin', $attributes);
    }

    private function getAllServers($service = 'mailbox')
    {
        return $this->zimbraConnect->request('GetAllServersRequest', null, 'urn:zimbraAdmin', array(
            'service' => $service
        ));
    }

    public function getServers()
    {
        foreach ($this->getAllServers()->children()->GetAllServersResponse->children() as $server) {
            $results[] = new Server($server);
        }

        return $results;
    }

    public function getServer($server, $by = 'name')
    {
        $params = array(
            'server' => array(
                '_'  => $server,
                'by' => $by,
            )
        );

        $response = $this->zimbraConnect->request('GetServerRequest', null, 'urn:zimbraAdmin', array(), $params);
        $servers = $response->children()->GetServerResponse->children();
        return new Server($servers[0]);
    }

    public function getQuotas(array $attributes, $sort = null, $targetServer = null)
    {
        $results = array();

        if (!is_null($sort)) {
            $attributes['sortBy'] = $sort[0];
            $attributes['sortAscending'] = $sort[1];
        }

        $response = $this->getQuotaUsage($attributes, $targetServer);
        $quotas = $response->children()->GetQuotaUsageResponse->children();

        $systemUsers = array();
        foreach (sfConfig::get('app_account_system_users_regexp') as $user) {
            $a = explode('.', $user);
            $systemUsers[] = $a[0];
        }

        foreach ($quotas as $quota) {
            $account = explode('@', $quota['name']);
            $b = explode('.', $account[0]);

            if (!in_array($b[0], $systemUsers)) {
                $results[(string) $quota['id']] = array(
                    'name' => (string) $quota['name'],
                    'used' => (string) $quota['used'],
                    'limit' => (string) $quota['limit'],
                );
            }
        }

        return $results;
    }

    public function getTotalQuota($domain)
    {
        $result = array(
            'diskUsage'       => 0,
            'diskProvisioned' => 0,
            'mailTotal'       => 0,
        );

        foreach ($this->getAllServers()->children()->GetAllServersResponse->children() as $server) {

            $response = $this->getQuotaUsage(array('domain' => $domain), (string) $server['id']);
            $result['mailTotal'] += (string) $response->children()->GetQuotaUsageResponse['searchTotal'];

            foreach ($response->children()->GetQuotaUsageResponse->children() as $account) {
                // La quota di postmaster è quella totale di dominio
                if ($account['name'] != 'postmaster@' . $domain) {
                    $result['diskUsage'] += $account['used'];
                    $result['diskProvisioned'] += $account['limit'];
                } else {
                    $result['diskLimit'] = (int) $account['limit'];
                }
            }
        }

        // rimuove dal conteggio gli account di sistema
        $result['mailTotal'] -= count(sfConfig::get('app_account_system_users_regexp')) - 1;

        $attrs = $this->getDomain($domain, 'name', array('zimbraDomainMaxAccounts'));
        $result['mailLimit'] = (int) $attrs[0];

        return $result;
    }

    public function createAccount($values)
    {
        $params = array();

        $params['name'] = $values['name'];
        unset($values['name']);

        $params['password'] = $values['password'];
        unset($values['password']);

        $params['attributes'] = $values;

        $response = $this->zimbraConnect->request('CreateAccountRequest', null, 'urn:zimbraAdmin', array(), $params);
        $accounts = $response->children()->CreateAccountResponse->children();

        return new Account($accounts[0]);
    }

    public function getAccount($domain, $by, $account)
    {
        $params = array(
            'account' => array(
                '_'  => $account,
                'by' => $by,
            )
        );

        $response = $this->zimbraConnect->request('GetAccountRequest', null, 'urn:zimbraAdmin', array(), $params);
        $accounts = $response->children()->GetAccountResponse->children();
        $account = new Account($accounts[0]);

        $aliases = $this->getAliases($domain);
        if (array_key_exists($account->name, $aliases)) {
            $account->setAliases($aliases[$account->name]);
        }

        return $account;
    }

    public function modifyAccount($values)
    {
        $params = array();
        $params['id'] = $values['id'];
        unset($values['id']);
        $params['attributes'] = $values;

        $response = $this->zimbraConnect->request('ModifyAccountRequest', null, 'urn:zimbraAdmin', array(), $params);
        $accounts = $response->children()->ModifyAccountResponse->children();

        return new Account($accounts[0]);
    }

    /**
     * Ritorna tutti gli alias del dominio.
     *
     * @param string $domain
     * @return array i nomi degli alias, strippati dal dominio
     */
    public function getAliases($domain)
    {
        $results = array();

        $response = $this->searchDirectory($domain, 0, 0, 'aliases');
        $aliases = $response->children()->SearchDirectoryResponse->children();

        foreach ($aliases as $alias) {
            $results[(string) $alias['targetName']][] = strstr($alias['name'], '@', true);
        }

        return $results;
    }

    public function getDomain($domain, $by = 'name', $attrs = array())
    {
        $attributes = array();

        if (!empty($attrs)) {
            $attributes['attrs'] = implode(',', $attrs);
        }

        $params = array(
            'domain' => array(
                '_'  => $domain,
                'by' => $by,
            )
        );

        $response = $this->zimbraConnect->request('GetDomainRequest', null, 'urn:zimbraAdmin', $attributes, $params);
        $domains = $response->children()->GetDomainResponse->children();

        return $domains[0]->children();
    }

    public function addAccountAlias($id, $alias)
    {
        $params = array(
            'id'    => $id,
            'alias' => $alias
        );

        $this->zimbraConnect->request('AddAccountAliasRequest', null, 'urn:zimbraAdmin', array(), $params);

        return true;
    }

    public function removeAccountAlias($id, $alias)
    {
        $params = array(
            'id'    => $id,
            'alias' => $alias
        );

        $this->zimbraConnect->request('RemoveAccountAliasRequest', null, 'urn:zimbraAdmin', array(), $params);

        return true;
    }

    public function getDistributionLists($domain)
    {
        $results = array();

        $response = $this->searchDirectory($domain, 0, 0, 'distributionlists');

        foreach ($response->children()->SearchDirectoryResponse->children() as $listData) {
            $results[] = new DistributionList($listData);
        }

        return $results;
    }

    public function getDistributionList($list, $by = 'id')
    {
        $params = array(
            'dl' => array(
                '_'  => $list,
                'by' => $by,
            )
        );

        $response = $this->zimbraConnect->request('GetDistributionListRequest', null, 'urn:zimbraAdmin', array(), $params);
        $lists = $response->children()->GetDistributionListResponse->children();

        return new DistributionList($lists[0]);
    }

    public function modifyDistributionList($values)
    {
        $params = array();
        $params['id'] = $values['id'];
        unset($values['id']);
        $params['attributes'] = $values;

        $response = $this->zimbraConnect->request('ModifyDistributionListRequest', null, 'urn:zimbraAdmin', array(), $params);
        $lists = $response->children()->ModifyDistributionListResponse->children();

        return new DistributionList($lists[0]);
    }

    public function addDistributionListMember($id, $member)
    {
        $params = array(
            'id'    => $id,
            'dlm' => $member
        );

        $this->zimbraConnect->request('AddDistributionListMemberRequest', null, 'urn:zimbraAdmin', array(), $params);

        return true;
    }

    public function removeDistributionListMember($id, $member)
    {
        $params = array(
            'id'    => $id,
            'dlm' => $member
        );

        $this->zimbraConnect->request('RemoveDistributionListMemberRequest', null, 'urn:zimbraAdmin', array(), $params);

        return true;
    }

    public function createDistributionList($values)
    {
        $params = array();

        $params['name'] = $values['name'];
        unset($values['name']);

        $params['attributes'] = $values;

        $response = $this->zimbraConnect->request('CreateDistributionListRequest', null, 'urn:zimbraAdmin', array(), $params);
        $lists = $response->children()->CreateDistributionListResponse->children();

        return new DistributionList($lists[0]);
    }

    public function deleteDistributionList($id)
    {
        $this->zimbraConnect->request('DeleteDistributionListRequest', null, 'urn:zimbraAdmin', array(), array('id' => $id));

        return true;
    }

    public function autoCompleteGal($domain, $name, $limit = 10)
    {
        $attributes = array(
            'domain' => $domain,
            'limit'  => $limit,
        );

        $response = $this->zimbraConnect->request('AutoCompleteGalRequest', null, 'urn:zimbraAdmin', $attributes, array('name' => $name));
        foreach ($response->children()->AutoCompleteGalResponse->children() as $cn) {
            foreach ($cn->children()->a as $a) {
                $result[(string) $a['n']] = (string) $a;
            }

            // Solo account, no gruppi
            if (!isset($result['type'])) {
                $results[] = $result;
            }
        }

        return $results;
    }

    public function noOp()
    {
        $this->zimbraConnect->request('NoOpRequest', null, 'urn:zimbraAdmin');
    }

}
