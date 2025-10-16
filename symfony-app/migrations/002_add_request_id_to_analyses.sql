-- Add request_id to analyses table to link back to original request
ALTER TABLE maestro.analyses
ADD COLUMN IF NOT EXISTS request_id UUID REFERENCES maestro.requests(id) ON DELETE CASCADE;

-- Create index for faster joins
CREATE INDEX IF NOT EXISTS idx_analyses_request_id ON maestro.analyses(request_id);

-- Add comment
COMMENT ON COLUMN maestro.analyses.request_id IS 'Reference to the original user request that triggered this analysis';
