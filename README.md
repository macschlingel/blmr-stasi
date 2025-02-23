# BLMR STASI
Statistics, Trends, Analytics, Surveillance & Insights for BLMR

A data collection tool that fetches event data from easyVerein's API for the BLMR calendar and stores it in a local database for analysis. It collects event details and participation data, tracking both event information and individual participant registrations.

## Features

- Fetches events from BLMR's easyVerein calendar (ID: 22014754)
- Handles API authentication:
  - Automatic token refresh after 15 days
  - Updates .env file with new token
  - Retries requests with refreshed token
- Tracks event details including:
  - Event name and description
  - Start and end times
  - Location information
  - Maximum allowed participants
  - Actual confirmed participants
- Stores participation data:
  - Individual participant registrations
  - Participation states (confirmed, cancelled, etc.)
  - Member IDs for analysis
- Updates data daily
- Handles API rate limiting gracefully

## Setup

1. Install required PHP extensions:
   ```bash
   # macOS
   brew install php
   
   # Ubuntu/Debian
   sudo apt install php-mysql php-curl
   ```

2. Clone and configure:
   ```bash
   git clone git@github.com:macschlingel/blmr-stasi.git
   cd blmr-stasi
   cp .env.example .env
   ```

3. Edit `.env` with your credentials:
   ```env
   API_TOKEN=your_easyverein_api_token
   DB_HOST=127.0.0.1
   DB_NAME=events_db
   DB_USER=eventuser
   DB_PASSWORD=eventpass
   ```

4. Start MariaDB:
   ```bash
   docker-compose up -d
   ```

## Usage

### Fetch Yesterday's Events (Default)
```bash
php src/EventFetcher.php
```

### Fetch Single Day
```bash
php src/EventFetcher.php 2024-03-14
```

### Fetch Specific Date Range
```bash
php src/EventFetcher.php 2024-02-01 2024-02-15
```

The script will:
- Process events day by day
- Show progress for each day
- Indicate new vs updated events
- Display a summary when complete

## Database Schema

### Events Table
```sql
CREATE TABLE `events` (
  `id` int(11) NOT NULL,
  `calendar_id` int(11) DEFAULT NULL,
  `name` varchar(255) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `location_name` varchar(255) DEFAULT NULL,
  `location_object` text DEFAULT NULL,
  `start_time` datetime DEFAULT NULL,
  `end_time` datetime DEFAULT NULL,
  `max_participants` int(11) DEFAULT NULL,
  `actual_participants` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
```

### Participations Table
```sql
CREATE TABLE `participations` (
  `id` int(11) NOT NULL,
  `event_id` int(11) NOT NULL,
  `member_id` int(11) NOT NULL,
  `state` tinyint NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `event_id` (`event_id`),
  KEY `member_id` (`member_id`),
  CONSTRAINT `participations_ibfk_1` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
```

## Rate Limiting

The script includes safeguards against API rate limiting:
- 1-second delay between requests
- Progressive backoff when hitting limits:
  - First retry: 5 seconds
  - Second retry: 10 seconds
  - Third retry: 15 seconds
  - Fourth retry: 20 seconds
  - Fifth retry: 25 seconds
- Maximum 5 retry attempts per request

## Error Handling

The script handles:
- API authentication issues
- Rate limiting
- Database connection problems
- Invalid date formats
- Missing environment variables

## Development

The project uses:
- easyVerein API v2.0
- MariaDB for data storage
- Docker for database containerization
- PHP for data collection
