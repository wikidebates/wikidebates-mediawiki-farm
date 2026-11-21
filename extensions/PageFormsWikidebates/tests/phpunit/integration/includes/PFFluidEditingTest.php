<?php

use MediaWiki\Title\Title;

/**
 * @covers PFCategoryAutocomplete
 * @covers PFAutocompleteSearch
 * @covers PFTreeInput
 * @covers PFValuesUtils
 * @group Database
 * @group PageForms
 */
class PFFluidEditingTest extends MediaWikiIntegrationTestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->setMwGlobals( [
			'wgPageFormsUseDisplayTitle' => false,
			'wgPageFormsMaxAutocompleteValues' => 1000,
			'wgPageFormsAutocompleteWordFallback' => true,
			'wgPageFormsCategoryTraversalLimit' => 2000,
		] );
	}

	public function testDiscoveryIsNotTruncatedByTwentyMatchingPages(): void {
		$this->insertPage( 'Category:PFFluidRoot', '' );
		for ( $i = 0; $i < 25; $i++ ) {
			$this->insertPage( "PFFluid Match $i", '[[Category:PFFluidRoot]]' );
		}
		$this->insertPage( 'Category:PFFluidChild', '[[Category:PFFluidRoot]]' );
		$this->insertPage( 'PFFluid Match', '[[Category:PFFluidChild]]' );
		$values = PFValuesUtils::getAllPagesForCategory( 'PFFluidRoot', 3, 'PFFluid Match' );
		$this->assertArrayHasKey( 'PFFluid Match', $values );
		$this->assertSame( 'PFFluid Match', array_key_first( $values ) );
		$this->assertLessThanOrEqual( 20, count( $values ) );
	}

	public function testDepthAndCyclesAndDuplicateMembership(): void {
		$this->insertPage( 'Category:PFFluidRoot', '[[Category:PFFluidChild]]' );
		$this->insertPage( 'Category:PFFluidChild', '[[Category:PFFluidRoot]]' );
		$this->insertPage( 'PFFluid Shared@', '[[Category:PFFluidRoot]][[Category:PFFluidChild]]' );
		$this->insertPage( 'PFFluid Child', '[[Category:PFFluidChild]]' );
		$this->assertCount( 1, PFValuesUtils::getAllPagesForCategory( 'PFFluidRoot', 1 ) );
		$this->assertCount( 2, PFValuesUtils::getAllPagesForCategory( 'PFFluidRoot', 5 ) );
	}

	public function testCategoryBudgetFailsExplicitly(): void {
		$this->setMwGlobals( 'wgPageFormsCategoryTraversalLimit', 1 );
		$this->insertPage( 'Category:PFFluidRoot', '' );
		$this->insertPage( 'Category:PFFluidChild', '[[Category:PFFluidRoot]]' );
		$this->expectException( LengthException::class );
		PFValuesUtils::getAllPagesForCategory( 'PFFluidRoot', 2, 'Test' );
	}

	public function testCrossesQueryBatchBoundaries(): void {
		$this->insertPage( 'Category:PFFluidRoot', '' );
		for ( $i = 0; $i < 51; $i++ ) {
			$this->insertPage( 'Category:PFFluidChild' . $i, '[[Category:PFFluidRoot]]' );
		}
		$this->insertPage( 'PFFluid Last Batch', '[[Category:PFFluidChild9]]' );
		$this->assertArrayHasKey( 'PFFluid Last Batch', PFValuesUtils::getAllPagesForCategory( 'PFFluidRoot', 2, 'PFFluid Last' ) );
	}

	public function testWordFallbackIsRestrictedToCategoryAndCanBeDisabled(): void {
		$this->insertPage( 'PFFluid Question About Existence Of God', '[[Category:PFFluidRoot]]' );
		$this->insertPage( 'PFFluid Outside Existence God', '' );
		$values = PFValuesUtils::getAllPagesForCategory( 'PFFluidRoot', 1, 'existence god' );
		$this->assertSame( [ 'PFFluid Question About Existence Of God' ], array_keys( $values ) );
		$this->setMwGlobals( 'wgPageFormsAutocompleteWordFallback', false );
		$this->assertSame( [], PFValuesUtils::getAllPagesForCategory( 'PFFluidRoot', 1, 'existence god' ) );
	}

	public function testFallbackDoesNotTreatSqlWildcardsAsOperators(): void {
		$this->insertPage( "PFFluid A Percent% Quote'", '[[Category:PFFluidRoot]]' );
		$this->insertPage( 'PFFluid Ordinary', '[[Category:PFFluidRoot]]' );
		$this->assertSame( [], PFValuesUtils::getAllPagesForCategory( 'PFFluidRoot', 1, "%' OR 1=1" ) );
	}

	public function testCanonicalTitleStillMatchesWithDisplayTitle(): void {
		$this->setMwGlobals( 'wgPageFormsUseDisplayTitle', true );
		$this->insertPage( 'PFFluid Canonical', '[[Category:PFFluidRoot]]' );
		$title = Title::newFromText( 'PFFluid Canonical' );
		$this->getDb()->newInsertQueryBuilder()->insertInto( 'page_props' )->row( [
			'pp_page' => $title->getArticleID(), 'pp_propname' => 'displaytitle', 'pp_value' => 'Completely different label',
		] )->caller( __METHOD__ )->execute();
		$values = PFValuesUtils::getAllPagesForCategory( 'PFFluidRoot', 1, 'PFFluid Canonical' );
		$this->assertSame( 'Completely different label', $values['PFFluid Canonical'] );
	}

	public function testRankingExactPrefixWordsAndNumericKeys(): void {
		$values = [ 'A god existence discussion', 'God existence extended', 'God existence' ];
		$this->assertSame( [ 2, 1, 0 ], array_keys( PFAutocompleteSearch::rank( $values, 'god existence' ) ) );
		$this->assertSame( [ 123, 'Tail@' ], array_keys( PFAutocompleteSearch::rank( [ 'Tail@' => '123 other', 123 => '123' ], '123', true ) ) );
	}

	public function testUnicodeCaseAndNormalization(): void {
		$this->assertSame( 'etat', PFAutocompleteSearch::normalize( 'ETAT' ) );
		$this->assertSame( "\u{00e9}tat", PFAutocompleteSearch::normalize( "\u{00c9}tat" ) );
		$this->assertSame( "\u{00e9}tat", PFAutocompleteSearch::normalize( "E\u{0301}tat" ) );
	}

	public function testUninitializedTreeKeepsItsSubmittedValueAndEscapesName(): void {
		$html = PFTreeInput::getHTML( 'First', "Item[Quote'Value]", false, false, [ 'structure' => "* First\n* Second" ] );
		$dom = new DOMDocument();
		$dom->loadHTML( $html );
		$input = $dom->getElementsByTagName( 'input' )->item( 0 );
		$this->assertSame( 'First', $input->getAttribute( 'value' ) );
		$this->assertSame( "Item[Quote'Value]", $input->getAttribute( 'name' ) );
	}
}
