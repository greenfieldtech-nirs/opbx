package state

import (
	"context"
	"encoding/json"
	"fmt"
	"os"
	"sync"
	"time"

	"github.com/nirsolutions/opbx-dialer-worker/internal/api"
	"github.com/nirsolutions/opbx-dialer-worker/pkg/models"
	"github.com/rs/zerolog/log"
)

// Persister handles worker state persistence
type Persister struct {
	client   *api.Client
	workerID string
	stateDir string
	mu       sync.RWMutex
	state    *models.WorkerState
	ticker   *time.Ticker
	done     chan struct{}
}

// NewPersister creates a new state persister
func NewPersister(client *api.Client, workerID, stateDir string) *Persister {
	return &Persister{
		client:   client,
		workerID: workerID,
		stateDir: stateDir,
		state: &models.WorkerState{
			WorkerID:        workerID,
			ActiveCampaigns: make(map[int64]*models.CampaignState),
			RetryQueueState: make(map[string]*models.RetryState),
			UpdatedAt:       time.Now(),
		},
		done: make(chan struct{}),
	}
}

// Start begins periodic state persistence
func (p *Persister) Start(ctx context.Context) {
	// Load existing state
	if err := p.loadState(ctx); err != nil {
		log.Warn().Err(err).Msg("Failed to load persisted state")
	}

	// Start periodic save
	p.ticker = time.NewTicker(30 * time.Second)
	go func() {
		for {
			select {
			case <-ctx.Done():
				p.Stop()
				return
			case <-p.done:
				return
			case <-p.ticker.C:
				if err := p.Save(ctx); err != nil {
					log.Error().Err(err).Msg("Failed to persist state")
				}
			}
		}
	}()

	log.Info().Msg("State persister started")
}

// Stop stops the state persister
func (p *Persister) Stop() {
	if p.ticker != nil {
		p.ticker.Stop()
		close(p.done)
	}

	// Final save
	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()
	if err := p.Save(ctx); err != nil {
		log.Error().Err(err).Msg("Failed to save final state")
	}

	log.Info().Msg("State persister stopped")
}

// Save persists the current state to Laravel API and local file
func (p *Persister) Save(ctx context.Context) error {
	p.mu.RLock()
	p.state.UpdatedAt = time.Now()
	stateCopy := *p.state
	p.mu.RUnlock()

	// Save to Laravel API
	if err := p.client.PersistState(ctx, &stateCopy); err != nil {
		log.Error().Err(err).Msg("Failed to persist state to API")
		// Continue to save locally as backup
	}

	// Save to local file as backup
	if p.stateDir != "" {
		if err := p.saveToFile(&stateCopy); err != nil {
			log.Error().Err(err).Msg("Failed to save state to file")
			return err
		}
	}

	return nil
}

// Load retrieves the persisted state
func (p *Persister) Load(ctx context.Context) (*models.WorkerState, error) {
	p.mu.RLock()
	defer p.mu.RUnlock()
	return p.state, nil
}

// UpdateCampaignState updates state for a specific campaign
func (p *Persister) UpdateCampaignState(campaignID int64, state *models.CampaignState) {
	p.mu.Lock()
	defer p.mu.Unlock()
	p.state.ActiveCampaigns[campaignID] = state
	p.state.LastProcessedAt = time.Now()
}

// UpdateRetryState updates retry queue state
func (p *Persister) UpdateRetryState(key string, state *models.RetryState) {
	p.mu.Lock()
	defer p.mu.Unlock()
	p.state.RetryQueueState[key] = state
}

// RemoveCampaignState removes a campaign from state
func (p *Persister) RemoveCampaignState(campaignID int64) {
	p.mu.Lock()
	defer p.mu.Unlock()
	delete(p.state.ActiveCampaigns, campaignID)
}

// loadState loads state from Laravel API or local file
func (p *Persister) loadState(ctx context.Context) error {
	// Try to load from Laravel API first
	state, err := p.client.GetState(ctx, p.workerID)
	if err == nil {
		p.mu.Lock()
		p.state = state
		p.mu.Unlock()
		log.Info().Msg("Loaded state from API")
		return nil
	}

	log.Warn().Err(err).Msg("Failed to load state from API, trying local file")

	// Fallback to local file
	if p.stateDir != "" {
		if err := p.loadFromFile(); err != nil {
			return fmt.Errorf("failed to load from file: %w", err)
		}
		log.Info().Msg("Loaded state from local file")
		return nil
	}

	return fmt.Errorf("no state available")
}

// saveToFile saves state to local file
func (p *Persister) saveToFile(state *models.WorkerState) error {
	if err := os.MkdirAll(p.stateDir, 0755); err != nil {
		return fmt.Errorf("failed to create state directory: %w", err)
	}

	filePath := fmt.Sprintf("%s/worker-%s-state.json", p.stateDir, p.workerID)
	data, err := json.MarshalIndent(state, "", "  ")
	if err != nil {
		return fmt.Errorf("failed to marshal state: %w", err)
	}

	if err := os.WriteFile(filePath, data, 0644); err != nil {
		return fmt.Errorf("failed to write state file: %w", err)
	}

	return nil
}

// loadFromFile loads state from local file
func (p *Persister) loadFromFile() error {
	filePath := fmt.Sprintf("%s/worker-%s-state.json", p.stateDir, p.workerID)
	data, err := os.ReadFile(filePath)
	if err != nil {
		if os.IsNotExist(err) {
			// No existing state, start fresh
			return nil
		}
		return fmt.Errorf("failed to read state file: %w", err)
	}

	var state models.WorkerState
	if err := json.Unmarshal(data, &state); err != nil {
		return fmt.Errorf("failed to unmarshal state: %w", err)
	}

	p.state = &state
	return nil
}

// GetStateDir returns the state directory path
func (p *Persister) GetStateDir() string {
	return p.stateDir
}
