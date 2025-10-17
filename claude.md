# MAESTRO Dashboard - Symfony 7.3 Application

## Project Context
You are developing a Symfony 7.3 dashboard for MAESTRO, an AI-powered multi-agent orchestration platform. The system uses n8n workflows to coordinate AI agents (PM, US, Dev, Test, Deploy) that analyze and process development requests.

**Note**: L'Agent Cadrage a été supprimé le 2025-10-17 pour simplifier le workflow et éliminer l'ambiguïté sur le scope des User Stories.

## Technical Stack
- PHP 8.2
- Symfony 7.3
- PostgreSQL 15 (existing database)
- Bootstrap 5.3 for UI
- Webpack Encore for assets
- Doctrine ORM

## Database Schema (Existing)
The PostgreSQL database `maestro_platform` already exists with schema `maestro`:
```sql
-- Analyses table (requests analyzed by PM agent)
maestro.analyses (
  id UUID PRIMARY KEY,
  request_text TEXT,
  analysis_type VARCHAR(50),
  complexity VARCHAR(10), -- XS, S, M, L, XL
  priority VARCHAR(20),
  confidence DECIMAL(3,2),
  agents_needed JSONB,
  estimated_hours INTEGER,
  next_steps JSONB,
  risks JSONB,
  full_response JSONB,
  webhook_execution_id VARCHAR(255),
  created_at TIMESTAMP
)

-- User Stories table
maestro.user_stories (
  id UUID PRIMARY KEY,
  project_id UUID,
  analysis_id UUID,
  stories JSONB,
  acceptance_criteria JSONB,
  story_points INTEGER,
  priority_order JSONB,
  dependencies JSONB,
  created_at TIMESTAMP
)

-- Projects table
maestro.projects (
  id UUID PRIMARY KEY,
  slug VARCHAR(50) UNIQUE,
  name VARCHAR(255),
  description TEXT,
  config JSONB,
  created_at TIMESTAMP
)
```

## Required Features

### 1. Dashboard Homepage
Create `src/Controller/DashboardController.php`:
- Route: `/`
- Display real-time statistics:
  - Total analyses count
  - Average confidence score
  - Complexity distribution (pie chart)
  - Recent analyses list (last 10)
  - Active agents status

### 2. Request Submission
Create `src/Controller/RequestController.php`:
- Route: `/request/new`
- Form with fields:
  - Title (text)
  - Description (textarea)
  - Type (select: FEATURE, BUG, ENHANCEMENT)
  - Priority (select: LOW, MEDIUM, HIGH, CRITICAL)
- On submit: Call n8n webhook at `http://n8n:5678/webhook/orchestrate`
- Show loading state while processing
- Redirect to analysis detail page when complete

### 3. Analysis Details
Create `src/Controller/AnalysisController.php`:
- Route: `/analysis/{id}`
- Display complete analysis with:
  - Request information
  - PM analysis results
  - Cadrage details (if exists)
  - User stories (if exists)
  - Timeline visualization
  - Confidence score with progress bar

### 4. Projects Management
Create `src/Controller/ProjectController.php`:
- Routes: `/projects`, `/project/{slug}`
- CRUD operations for projects
- Display project-specific analyses

### 5. API Endpoints
Create `src/Controller/Api/`:
- `/api/stats` - Return JSON statistics
- `/api/analyses` - List analyses with pagination
- `/api/health` - Health check endpoint

## Entity Classes

### `src/Entity/Analysis.php`
```php
namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'analyses', schema: 'maestro')]
class Analysis
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;
    
    #[ORM\Column(type: 'text')]
    private string $requestText;
    
    #[ORM\Column(length: 50)]
    private string $analysisType;
    
    #[ORM\Column(length: 10)]
    private string $complexity;
    
    #[ORM\Column(type: 'json')]
    private array $agentsNeeded = [];
    
    #[ORM\Column(type: 'decimal', precision: 3, scale: 2)]
    private float $confidence;
    
    // Add getters/setters
}
```

Create similar entities for `Cadrage`, `UserStory`, and `Project`.

## Services

### `src/Service/N8nService.php`
```php
namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class N8nService
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private string $n8nUrl = 'http://n8n:5678'
    ) {}
    
    public function triggerOrchestration(string $request): array
    {
        $response = $this->httpClient->request('POST', $this->n8nUrl . '/webhook/orchestrate', [
            'json' => ['request' => $request]
        ]);
        
        return $response->toArray();
    }
}
```

### `src/Service/StatsService.php`
For calculating dashboard statistics from database.

## UI Templates (Twig)

### `templates/base.html.twig`
Use Bootstrap 5.3 with a modern, clean design:
- Purple/indigo gradient header
- Sidebar navigation
- Responsive layout

### `templates/dashboard/index.html.twig`
- Statistics cards with icons
- Chart.js for complexity distribution
- DataTables for analyses list
- Real-time updates via Turbo Streams

### Key UI Components
- Status badges for complexity (XS=green, S=blue, M=yellow, L=orange, XL=red)
- Progress bars for confidence scores
- Timeline visualization for multi-agent workflows
- Loading spinners during API calls

## Environment Configuration

`.env.local`:
```
DATABASE_URL="postgresql://maestro_admin:MaestroDB2024Secure!@postgres:5432/maestro_platform?serverVersion=15&charset=utf8"
N8N_WEBHOOK_URL="http://n8n:5678/webhook"
GEMINI_API_KEY="your-key-here"
```

## Webpack Encore Configuration
Configure for:
- Bootstrap 5.3 CSS/JS
- Chart.js
- DataTables
- Custom SCSS with purple/indigo theme
- Turbo for SPA-like experience

## Testing Requirements
- Functional tests for all controllers
- Unit tests for services
- Use fixtures for test data
- Mock n8n webhook calls in tests

## Development Workflow
1. Start with database connection and entities
2. Create basic CRUD for analyses
3. Implement n8n webhook integration
4. Build dashboard with real-time stats
5. Add Chart.js visualizations
6. Implement Turbo for dynamic updates
7. Add comprehensive error handling
8. Create admin panel for system monitoring

## Performance Considerations
- Use Doctrine query optimization
- Implement Redis caching for stats
- Paginate large result sets
- Use Symfony Messenger for async operations
- Optimize JSONB queries with indexes

## Security
- Validate all user inputs
- Use CSRF tokens on forms
- Implement rate limiting
- Secure API endpoints with authentication
- Sanitize JSONB data before display

## Symfony UX Turbo
The application uses Symfony UX Turbo for SPA-like experience. However, some forms need Turbo disabled:

### When to disable Turbo
Add `data-turbo="false"` attribute to forms that:
1. **Redirect after POST** - Forms that redirect to another page after submission
2. **Delete operations** - Forms that delete resources and redirect
3. **File uploads** - Forms that handle file uploads
4. **External redirects** - Forms that redirect to external URLs

### Example
```twig
{# Form with Turbo disabled #}
<form method="post" action="{{ path('app_analysis_delete', {id: analysis.id}) }}" data-turbo="false">
    <input type="hidden" name="_token" value="{{ csrf_token('delete_analysis_' ~ analysis.id) }}">
    <button type="submit" class="btn btn-danger">Supprimer</button>
</form>
```

### Common Error
If you see this error in the browser console:
```
Error: Form responses must redirect to another location
    at O.requestSucceededWithResponse
```
It means Turbo is intercepting a form that redirects. Add `data-turbo="false"` to fix it.

Please create this Symfony application with clean, maintainable code following Symfony best practices and PSR standards.