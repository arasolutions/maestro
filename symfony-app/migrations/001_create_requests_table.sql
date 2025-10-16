-- Create requests table for user initial requests
CREATE TABLE IF NOT EXISTS maestro.requests (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    request_text TEXT NOT NULL,
    request_type VARCHAR(50), -- FEATURE, BUG, ENHANCEMENT, REFACTORING
    priority VARCHAR(20), -- LOW, MEDIUM, HIGH, CRITICAL
    status VARCHAR(20) DEFAULT 'PENDING', -- PENDING, PROCESSING, COMPLETED, FAILED
    project_id UUID REFERENCES maestro.projects(id) ON DELETE SET NULL,
    webhook_execution_id VARCHAR(255),
    error_message TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Create index for faster queries
CREATE INDEX idx_requests_status ON maestro.requests(status);
CREATE INDEX idx_requests_created_at ON maestro.requests(created_at DESC);
CREATE INDEX idx_requests_project_id ON maestro.requests(project_id);

-- Add comment for documentation
COMMENT ON TABLE maestro.requests IS 'Initial user requests before AI analysis';
COMMENT ON COLUMN maestro.requests.status IS 'PENDING: awaiting processing, PROCESSING: n8n workflow running, COMPLETED: analysis created, FAILED: error occurred';
