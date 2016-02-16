<?php
namespace ApacheSolrForTypo3\Solr\Tests\Integration\Plugin\Results;

/***************************************************************
 *  Copyright notice
 *
 *  (c) 2010-2015 Timo Schmidt <timo.schmidt@dkd.de>
 *  All rights reserved
 *
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 2 of the License, or
 *  (at your option) any later version.
 *
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/

use ApacheSolrForTypo3\Solr\Site;
use ApacheSolrForTypo3\Solr\Tests\Integration\IntegrationTest;
use DOMDocument;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;
use TYPO3\CMS\Frontend\Page\PageGenerator;

/**
 * Integration testcase to test the results plugin.
 *
 * @author Timo Schmidt
 * @package TYPO3
 * @subpackage solr
 */
class ResultsTest extends IntegrationTest
{

    /**
     * Executed after each test. Emptys solr and checks if the index is empty
     */
    public function tearDown()
    {
        $this->cleanUpSolrServerAndAssertEmpty();
        parent::tearDown();
    }

    /**
     * @test
     */
    public function canDoAFacetedAndSortedSearch()
    {
        $searchResults = $this->importTestDataSetAndGetInitializedResultPlugin(array(1, 2, 3, 4), 'can_render_results_plugin.xml');

        $_GET['q'] = 'prices';
        $_GET['tx_solr']['filter'][0] = rawurlencode('subtitle:men');
        $_GET['tx_solr']['sort'] = 'title asc';

        $result = $searchResults->main('', array());

        $sortingLink = '<a href="index.php?id=1&amp;q=prices&amp;tx_solr%5Bfilter%5D%5B0%5D=subtitle%253Amen&amp;tx_solr%5Bsort%5D=title%20desc">Title</a>';
        $this->assertContains($sortingLink, $result, 'Could not find sorting link in search result');

        $subTitleFacetingLink = 'index.php?id=1&amp;q=prices&amp;tx_solr%5Bfilter%5D%5B0%5D=subtitle%253Amen&amp;tx_solr%5Bsort%5D=title%20asc&amp;foo=bar';
        $this->assertContains($subTitleFacetingLink, $result, 'Could not find faceting link in results');

        $productDescription = 'jeans products';
        $this->assertContains($productDescription, $result, 'Could not find test product description in content');

        $this->assertContains('pages/3/0/0/0', $result, 'Could not find page 3 in result set');

        $this->assertContains('pages/2/0/0/0', $result, 'Could not find page 2 in result set');

        $this->assertContains('Displaying results 1 to 2 of 2.', $result, 'Frontend output indicates wrong amount of results');

        $resultPositionDocument3 = strpos($result, 'pages/3/0/0/0');
        $resultPositionDocument2 = strpos($result, 'pages/2/0/0/0');
        $this->assertTrue($resultPositionDocument3 < $resultPositionDocument2, 'Could not find document 3 before 2, sorting not working?');

        $this->assertContains('<span class="results-highlight">prices</span>', $result, 'Could not find highlighting in response');

        $this->assertContains('class="facet"', $result, 'Facet links do not contain facet class from TypoScript setup');

        $this->assertContains('10€', $result, 'Search response did not contain price of product');

        $this->assertPaginationNotVisible($result);
    }

    /**
     * @test
     */
    public function canDoAPaginatedSearch()
    {
        $searchResults = $this->importTestDataSetAndGetInitializedResultPlugin(array(1, 2, 3, 4, 5, 6, 7, 8), 'can_render_results_plugin.xml');

        $_GET['q'] = '*';
        $resultPage1 = $searchResults->main('', array());

        $this->assertPaginationVisible($resultPage1);
        $this->assertContains('Displaying results 1 to 5', $resultPage1, 'Wrong result count indicated in template');
    }

    /**
     * @test
     */
    public function canOpenSecondPageOfPaginatedSearch()
    {
        $searchResults = $this->importTestDataSetAndGetInitializedResultPlugin(array(1, 2, 3, 4, 5, 6, 7, 8), 'can_render_results_plugin.xml');

        //now we jump to the second page
        $_GET['q'] = '*';
        $searchResults->piVars['page'] = 1;
        $resultPage2 = $searchResults->main('', array());

        $this->assertPaginationVisible($resultPage2);
        $this->assertContains('Displaying results 6 to 8', $resultPage2, 'Wrong result count indicated in template');
    }

    /**
     * @test
     */
    public function canDoASearchThatDoesNotReturnAnyResults()
    {
        $searchResults = $this->importTestDataSetAndGetInitializedResultPlugin(array(1, 2, 3, 4, 5, 6, 7, 8), 'can_render_results_plugin.xml');

        //now we jump to the second page
        $_GET['q'] = 'nothingwillbefound';
        $resultPage1 = $searchResults->main('', array());
        $this->assertNothingWasFound($resultPage1);
    }

    /**
     * @test
     */
    public function canGetADidYouMeanProposalForATypo()
    {
        $searchResults = $this->importTestDataSetAndGetInitializedResultPlugin(array(1, 2, 3, 4, 5, 6, 7, 8), 'can_render_results_plugin.xml');

        //not in the content but we expect to get shoes suggested
        $_GET['q'] = 'shoo';
        $resultPage1 = $searchResults->main('', array());

        $this->assertContains("Did you mean", $resultPage1, 'Could not find did you mean in response');
        $this->assertContains("shoes", $resultPage1, 'Could not find shoes in response');
    }

    /**
     * @test
     */
    public function canShowLastSearchesFromSessionInResponse()
    {
        $searchResults = $this->importTestDataSetAndGetInitializedResultPlugin(array(1, 2, 3, 4, 5, 6, 7, 8), 'can_render_results_plugin.xml');
        //not in the content but we expect to get shoes suggested
        $_GET['q'] = 'shoe';
        $resultPage1 = $searchResults->main('', array());
        $resultPage2 = $searchResults->main('', array());

        $this->assertContainerByIdContains('rel="nofollow">shoe</a>', $resultPage2, 'tx-solr-lastsearches');
    }

    /**
     * @test
     */
    public function canShowLastSearchesFromDatabaseInResponse()
    {
        $searchResults = $this->importTestDataSetAndGetInitializedResultPlugin(array(1, 2, 3, 4, 5, 6, 7, 8), 'can_render_results_plugin.xml');

        $overwriteConfiguration = array();
        $overwriteConfiguration['search.']['lastSearches.']['mode'] = 'global';

        /** @var $configurationManager \ApacheSolrForTypo3\Solr\System\Configuration\ConfigurationManager */
        $configurationManager = GeneralUtility::makeInstance('ApacheSolrForTypo3\Solr\System\Configuration\ConfigurationManager');
        $configurationManager->getTypoScriptConfiguration()->mergeSolrConfiguration($overwriteConfiguration);

        //not in the content but we expect to get shoes suggested
        $_GET['q'] = 'shoe';
        $resultPage1 = $searchResults->main('', array());
        $resultPage2 = $searchResults->main('', array());

        $this->assertContainerByIdContains('rel="nofollow">shoe</a>', $resultPage2, 'tx-solr-lastsearches');
    }

    /**
     * @test
     */
    public function canShowFrequentSearchesInResponse()
    {
        $searchResults = $this->importTestDataSetAndGetInitializedResultPlugin(array(1, 2, 3, 4, 5, 6, 7, 8), 'can_render_results_plugin.xml');
        //not in the content but we expect to get shoes suggested
        $_GET['q'] = 'shoe';
        $resultPage1 = $searchResults->main('', array());
        $resultPage2 = $searchResults->main('', array());
            //@todo this testcase fails when $GLOBALS['TSFE']->fe_user->id is set to a value that is to long
        $this->assertContainerByIdContains('rel="nofollow">shoe</a>', $resultPage2, 'tx-solr-frequent-searches');
    }

    /**
     * @test
     */
    public function canKeepPiVarsInForm()
    {
        $searchResults = $this->importTestDataSetAndGetInitializedResultPlugin(array(1, 2, 3, 4, 5, 6, 7, 8), 'can_render_results_plugin.xml');
        //now we jump to the second page
        $_GET['q'] = 'prices';
        $searchResults->piVars['tx_solr']['sort'] = 'title asc';
        $resultPage2 = $searchResults->main('', array());
        $this->assertContains('<input type="hidden" name="tx_solr[tx_solr][sort]" value="title+asc" />', $resultPage2, 'Hidden sorting field was not found in the form');
    }

    /**
     * @test
     */
    public function canRenderPageHierarchyFacet()
    {
        $searchResults = $this->importTestDataSetAndGetInitializedResultPlugin(array(1, 2, 3, 4, 5, 6, 7, 8), 'can_render_results_plugin.xml');
        $_GET['q'] = 'prices';
        $resultPage = $searchResults->main('', array());

        $this->assertContains('facet-type-hierarchy', $resultPage, 'Did not render hierarchy facet in the response');
        $this->assertContains('class="rootlinefacet-item"', $resultPage, 'Hierarchy facet items did not contain expected class from TypoScript');
        $this->assertContains('index.php?id=1&amp;q=prices&amp;tx_solr%5Bfilter%5D%5B0%5D=pageHierarchy%253A%252F1%252F2&amp;', $resultPage, 'Result page did not contain hierarchical facet link');
    }

    /**
     * @test
     */
    public function canDoAnInitialSearchWithCustomQueryString()
    {
        $searchResults = $this->importTestDataSetAndGetInitializedResultPlugin(array(1, 2, 3, 4, 5, 6, 7, 8), 'can_render_results_plugin.xml');

        $overwriteConfiguration = array();
        $overwriteConfiguration['search.']['initializeWithQuery'] = 'products';
        $overwriteConfiguration['search.']['showResultsOfInitialQuery'] = 1;

        /** @var $configurationManager \ApacheSolrForTypo3\Solr\System\Configuration\ConfigurationManager */
        $configurationManager = GeneralUtility::makeInstance('ApacheSolrForTypo3\Solr\System\Configuration\ConfigurationManager');
        $configurationManager->getTypoScriptConfiguration()->mergeSolrConfiguration($overwriteConfiguration);

        $resultPage = $searchResults->main('', array());

        $this->assertContains('Found 2 results', $resultPage, 'Did not render hierarchy facet in the response');
    }

    /**
     * @test
     */
    public function canApplyCustomTypoScriptFilters()
    {
        $searchResults = $this->importTestDataSetAndGetInitializedResultPlugin(array(1, 2, 3, 4, 5, 6, 7, 8), 'can_render_results_plugin.xml');

        $overwriteConfiguration = array();
        $overwriteConfiguration['search.']['query.']['filter.']['subtitle:men'] = 'subTitle:men';

        /** @var $configurationManager \ApacheSolrForTypo3\Solr\System\Configuration\ConfigurationManager */
        $configurationManager = GeneralUtility::makeInstance('ApacheSolrForTypo3\Solr\System\Configuration\ConfigurationManager');
        $configurationManager->getTypoScriptConfiguration()->mergeSolrConfiguration($overwriteConfiguration);

        $_GET['q'] = '*';

        $resultPage = $searchResults->main('', array());
        $this->assertContains('pages/2/0/0/0', $resultPage, 'Could not find page 2 in result set');
        $this->assertContains('pages/3/0/0/0', $resultPage, 'Could not find page 3 in result set');
        $this->assertContains('pages/6/0/0/0', $resultPage, 'Could not find page 6 in result set');
        $this->assertContains('pages/7/0/0/0', $resultPage, 'Could not find page 7 in result set');
    }

    /**
     * @param array $importPageIds
     * @param string $fixtureName
     * @return \ApacheSolrForTypo3\Solr\Plugin\Results\Results
     */
    private function importTestDataSetAndGetInitializedResultPlugin($importPageIds, $fixture)
    {
        $this->importDataSetFromFixture($fixture);

        foreach ($importPageIds as $importPageId) {
            $GLOBALS['TT'] = $this->getMock('\\TYPO3\\CMS\\Core\\TimeTracker\\TimeTracker', array(), array(), '', false);
            $fakeTSFE = $this->getConfiguredTSFE(array(), $importPageId);
            $fakeTSFE->newCObj();

            $GLOBALS['TSFE'] = $fakeTSFE;
            PageGenerator::pagegenInit();
            PageGenerator::renderContent();

            /** @var $pageIndexer \ApacheSolrForTypo3\Solr\Typo3PageIndexer */
            $pageIndexer = GeneralUtility::makeInstance('ApacheSolrForTypo3\Solr\Typo3PageIndexer', $fakeTSFE);
            $pageIndexer->indexPage();
        }

        sleep(3);

        /** @var $beUser  \TYPO3\CMS\Core\Authentication\BackendUserAuthentication */
        $beUser = GeneralUtility::makeInstance('TYPO3\CMS\Core\Authentication\BackendUserAuthentication');
        $GLOBALS['BE_USER'] = $beUser;

        /** @var $searchResults \ApacheSolrForTypo3\Solr\Plugin\Results\Results */
        $searchResults =  GeneralUtility::makeInstance('ApacheSolrForTypo3\Solr\Plugin\Results\Results');
        $fakeTSFE->newCObj();
        $fakeTSFE->fe_user->id = 'dsds';
        $searchResults->cObj = $fakeTSFE->cObj;
        return $searchResults;
    }

    /**
     * @param string $content
     * @param string $id
     * @return string
     */
    protected function getIdContent($content, $id)
    {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->loadHTML('<?xml version="1.0" encoding="UTF-8"?>' . PHP_EOL . $content);

        return $dom->saveXML($dom->getElementById($id));
    }


    /**
     * Assertion to check if the pagination markup is present in the response.
     *
     * @param string $content
     */
    protected function assertPaginationVisible($content)
    {
        $this->assertContains('class="tx-pagebrowse-first"', $content, 'No first page link found. Pagination broken?');
        $this->assertContains('class="tx-pagebrowse-last"', $content, 'No first last link found. Pagination broken?');
    }

    /**
     * Assertion to check if the pagination markup is not present in the response.
     *
     * @param string $content
     */
    protected function assertPaginationNotVisible($content)
    {
        $this->assertNotContains('class="tx-pagebrowse-first"', $content, 'No first page link found. Pagination broken?');
        $this->assertNotContains('class="tx-pagebrowse-last"', $content, 'No first last link found. Pagination broken?');
    }

    /**
     * Assert that the results page contains a "Nothing found" message.
     *
     * @param string $content
     */
    protected function assertNothingWasFound($content)
    {
        $this->assertContains('Nothing found', $content, 'Asserted that nothing was found but the text, that nothing was found was not present in the response');
    }

    /**
     * Assert that a docContainer with a specific id contains an expected content snipped.
     *
     * @param string $expectedToContain
     * @param string $content
     * @param $id
     */
    protected function assertContainerByIdContains($expectedToContain, $content, $id)
    {
        $containerContent = $this->getIdContent($content, $id);
        $this->assertContains($expectedToContain, $containerContent, 'Failed asserting that container with id ' . $id .' contains ' . $expectedToContain);
    }
}
