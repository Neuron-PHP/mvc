-- Test SQL file demonstrating multi-line strings with comment-like lines
-- This file tests that the importer correctly preserves string content

CREATE TABLE IF NOT EXISTS test_messages (
    id INT PRIMARY KEY,
    content TEXT
);

-- Insert a message with comment-like lines in the content
INSERT INTO test_messages (id, content) VALUES (1, 'System Log:
-- Processing started at 10:00
-- Step 1: Initialize
# Step 2: Validate input
-- Step 3: Execute
# Final step: Cleanup
Processing complete');

-- Another real comment
INSERT INTO test_messages (id, content) VALUES (2, 'Error Report:

# ERROR CODE: 500
-- Stack trace follows:
-- Line 1: main()
-- Line 2: process()

End of report');

-- Test with empty lines and mixed content
INSERT INTO test_messages (id, content) VALUES (3, 'Configuration:

-- Database: production
# Port: 3306

-- This is still part of the config string
Done');