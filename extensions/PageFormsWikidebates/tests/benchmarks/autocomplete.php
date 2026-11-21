<?php
/**
 * Read-only PageForms category/autocomplete benchmark.
 *
 * Included by maintenance/eval.php from a SINGLE command line.
 * MediaWiki 1.43's eval console evaluates standard input one line at a time;
 * sending this multi-line file as standard input would cause a ParseError.
 */

$category = getenv( 'PF_BENCH_CATEGORY' );
$query = getenv( 'PF_BENCH_QUERY' );
$depth = max( 1, min( 6, (int)( getenv( 'PF_BENCH_DEPTH' ) ?: 3 ) ) );
$rounds = max( 1, min( 20, (int)( getenv( 'PF_BENCH_ROUNDS' ) ?: 5 ) ) );

if ( !$category || !$query ) {
	throw new InvalidArgumentException( 'PF_BENCH_CATEGORY and PF_BENCH_QUERY are required' );
}

$durations = [];
$count = 0;

for ( $i = 0; $i < $rounds; $i++ ) {
	$start = hrtime( true );
	$values = PFValuesUtils::getAllPagesForCategory( $category, $depth, $query );
	$durations[] = ( hrtime( true ) - $start ) / 1000000;
	$count = count( $values );
}

$sorted = $durations;
sort( $sorted, SORT_NUMERIC );

printf(
	"wiki=%s category=%s query=%s depth=%d results=%d runs=%d\n",
	\MediaWiki\MediaWikiServices::getInstance()->getMainConfig()->get( 'DBname' ),
	$category,
	$query,
	$depth,
	$count,
	$rounds
);
printf(
	"cold=%.2f ms median=%.2f ms max=%.2f ms\n",
	$durations[0],
	$sorted[(int)floor( ( $rounds - 1 ) / 2 )],
	max( $durations )
);

$property = getenv( 'PF_BENCH_PROPERTY' );
$rawValues = getenv( 'PF_BENCH_VALUES' );

if ( $property && $rawValues ) {
	$terms = array_slice( array_map( 'trim', explode( ',', $rawValues ) ), 0, 50 );
	$start = hrtime( true );
	$mapped = PFMappingUtils::getValuesWithMappingProperty( $terms, $property );
	printf(
		"property=%s input=%d unique=%d elapsed=%.2f ms\n",
		$property,
		count( $terms ),
		count( $mapped ),
		( hrtime( true ) - $start ) / 1000000
	);
}
