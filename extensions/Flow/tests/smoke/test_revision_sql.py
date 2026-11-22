"""Execute the production MAX expression against real SQLite fixtures.

This tests SQL semantics, not the complete MediaWiki database abstraction.
The UUIDs are stored as binary values, like Flow's revision identifiers.
"""
from pathlib import Path
import re
import sqlite3
import unittest


class RevisionSqlTest(unittest.TestCase):
	def test_latest_revision_batch(self):
		source = (Path(__file__).resolve().parents[2] / 'includes/Data/Storage/RevisionStorage.php').read_text()
		method = source.split('protected function findMostRecent(', 1)[1].split('protected function findRevIdReal(', 1)[0]
		match = re.search(r"->select\( \[ 'rev_id' => ([\"'])(.*?)\1 \] \)", method)
		self.assertIsNotNone(match)
		expression = match.group(2)
		db = sqlite3.connect(':memory:')
		self.addCleanup(db.close)
		db.execute('CREATE TABLE flow_revision (rev_id BLOB PRIMARY KEY, rev_type TEXT, rev_type_id BLOB)')
		db.execute('CREATE INDEX flow_revision_type_id ON flow_revision (rev_type, rev_type_id, rev_id)')
		uuid = lambda number: number.to_bytes(11, 'big')
		rows = [(uuid(rev), 'post', uuid(topic)) for topic in (1, 2, 3) for rev in (topic * 10, topic * 10 + 1)]
		rows.append((uuid(999), 'post-summary', uuid(1)))
		db.executemany('INSERT INTO flow_revision VALUES (?, ?, ?)', rows)
		result = db.execute(
			f'SELECT {expression} AS rev_id FROM flow_revision '
			'WHERE rev_type = ? AND rev_type_id IN (?, ?, ?, ?) GROUP BY rev_type_id',
			('post', uuid(1), uuid(2), uuid(3), uuid(404))
		).fetchall()
		self.assertEqual([row[0] for row in result], [uuid(11), uuid(21), uuid(31)])
		self.assertEqual(db.execute("SELECT MAX('rev_id') FROM flow_revision").fetchone()[0], 'rev_id')


if __name__ == '__main__':
	unittest.main()
