"""New native coverage must preserve the original full-suite inventory."""
import importlib.util
from pathlib import Path
import tempfile
import unittest
import xml.etree.ElementTree as ET

ROOT = Path(__file__).resolve().parents[2]
SPEC = importlib.util.spec_from_file_location('additive_runner', ROOT / 'scripts/run-selected-tests.py')
M = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(M)


class AdditiveSuiteTest(unittest.TestCase):
    def test_addition_keeps_every_original_entry_order_and_guard(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            (root / 'tests/task7').mkdir(parents=True)
            (root / 'tests/task7/ActionSchedulerNativeTest.php').write_text('queue')
            (root / 'tests/task7/OtherTest.php').write_text('other')
            (root / 'evidence').mkdir()
            original = '<phpunit bootstrap="tests/bootstrap.php" failOnRisky="true"><testsuites><testsuite name="original"><directory>tests/smoke</directory><file>tests/first.php</file><file>tests/last.php</file></testsuite></testsuites></phpunit>'
            (root / 'phpunit-task4.xml').write_text(original)
            path = M.native_configuration(root)
            tree = ET.parse(root / path).getroot()
            self.assertEqual(tree.get('failOnRisky'), 'true')
            self.assertEqual(tree.get('bootstrap'), str(root / 'tests/bootstrap.php'))
            entries = list(tree.find('testsuites/testsuite'))
            self.assertEqual([(n.tag, n.text) for n in entries], [
                ('file', str(root / 'tests/task7/ActionSchedulerNativeTest.php')),
                ('directory', str(root / 'tests/smoke')),
                ('file', str(root / 'tests/first.php')), ('file', str(root / 'tests/last.php')),
                ('file', str(root / 'tests/task7/OtherTest.php'))])
            self.assertEqual((root / 'phpunit-task4.xml').read_text(), original)

    def test_old_inventory_without_new_directory_remains_unchanged(self):
        with tempfile.TemporaryDirectory() as temp:
            self.assertEqual(M.native_configuration(Path(temp)), 'phpunit-task4.xml')

    def test_ambiguous_suite_is_rejected(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            (root / 'tests/task7').mkdir(parents=True)
            (root / 'evidence').mkdir()
            for xml in ('<phpunit/>', '<phpunit><testsuites><testsuite/><testsuite/></testsuites></phpunit>'):
                (root / 'phpunit-task4.xml').write_text(xml)
                with self.assertRaises(ValueError):
                    M.native_configuration(root)


if __name__ == '__main__':
    unittest.main()
