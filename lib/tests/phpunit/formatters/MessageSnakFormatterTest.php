<?php

namespace Wikibase\Lib\Test;

use DataValues\StringValue;
use Wikibase\DataModel\Entity\PropertyId;
use Wikibase\DataModel\Snak\PropertyNoValueSnak;
use Wikibase\DataModel\Snak\PropertySomeValueSnak;
use Wikibase\DataModel\Snak\PropertyValueSnak;
use Wikibase\DataModel\Snak\Snak;
use Wikibase\Lib\MessageSnakFormatter;
use Wikibase\Lib\SnakFormatter;

/**
 * @covers Wikibase\Lib\MessageSnakFormatter
 * @uses Wikibase\DataModel\Entity\PropertyId
 * @uses Wikibase\DataModel\Snak\PropertyNoValueSnak
 * @uses Wikibase\DataModel\Snak\PropertySomeValueSnak
 *
 * @group ValueFormatters
 * @group DataValueExtensions
 * @group WikibaseLib
 * @group Wikibase
 *
 * @licence GNU GPL v2+
 * @author Daniel Kinzler
 * @author Thiemo Mättig
 */
class MessageSnakFormatterTest extends \MediaWikiTestCase {

	/**
	 * @param string $snakType
	 * @param string $format
	 *
	 * @return MessageSnakFormatter
	 */
	private function getFormatter( $snakType, $format ) {
		$message = $this->getMockBuilder( 'Message' )
			->disableOriginalConstructor()
			->getMock();

		foreach ( array( 'parse', 'text', 'plain' ) as $method ) {
			$message->expects( $this->any() )
				->method( $method )
				->will( $this->returnValue( $method ) );
		}

		return new MessageSnakFormatter( $snakType, $message, $format );
	}

	public function testGetFormat() {
		$formatter = $this->getFormatter( 'any', 'test' );

		$this->assertEquals( 'test', $formatter->getFormat() );
	}

	public function testCanFormatSnak() {
		$id = new PropertyId( 'P1' );
		$formatter = $this->getFormatter( 'novalue', 'test' );

		$snak = new PropertyNoValueSnak( $id );
		$this->assertTrue( $formatter->canFormatSnak( $snak ), $snak->getType() );

		$snak = new PropertySomeValueSnak( $id );
		$this->assertFalse( $formatter->canFormatSnak( $snak ), $snak->getType() );
	}

	/**
	 * @dataProvider snakProvider
	 */
	public function testFormatSnak_givenDifferentSnakTypes( Snak $snak, $expected ) {
		$formatter = $this->getFormatter( $snak->getType(), SnakFormatter::FORMAT_HTML );

		$this->assertEquals( $expected, $formatter->formatSnak( $snak ) );
	}

	public function snakProvider() {
		$id = new PropertyId( 'P1' );

		return array(
			array(
				new PropertyValueSnak( $id, new StringValue( 'string' ) ),
				'parse'
			),
			array(
				new PropertySomeValueSnak( $id ),
				'parse'
			),
			array(
				new PropertyNoValueSnak( $id ),
				'parse'
			),
		);
	}

	/**
	 * @dataProvider formatProvider
	 */
	public function testFormatSnak_givenDifferentFormats( $format, $expected ) {
		$snak = new PropertyValueSnak( new PropertyId( 'P1' ), new StringValue( 'string' ) );
		$formatter = $this->getFormatter( $snak->getType(), $format );

		$this->assertEquals( $expected, $formatter->formatSnak( $snak ) );
	}

	public function formatProvider() {
		return array(
			array( SnakFormatter::FORMAT_PLAIN, 'plain' ),
			array( SnakFormatter::FORMAT_WIKI, 'text' ),
			array( SnakFormatter::FORMAT_HTML, 'parse' ),
			array( SnakFormatter::FORMAT_HTML_WIDGET, 'parse' ),
			array( SnakFormatter::FORMAT_HTML_DIFF, 'parse' ),
		);
	}

}
