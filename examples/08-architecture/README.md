# Architecture Examples

Examples demonstrating architectural patterns: read/write splitting and sharding for horizontal scaling.

## Examples

### 01-read-write-splitting.php
Setting up read/write splitting for master-replica architecture.

**Topics covered:**
- Master-replica configuration
- Automatic query routing
- Read/write connection management
- Load balancing strategies

### 02-basic-sharding.php
Basic sharding setup with automatic query routing.

**Topics covered:**
- Shard configuration
- Automatic shard key extraction
- Query routing to correct shard
- Range-based sharding

### 03-sharding-strategies.php
Different sharding strategies for data distribution.

**Topics covered:**
- **Range Strategy** - Distribute data by numeric ranges
- **Hash Strategy** - Hash-based distribution
- **Modulo Strategy** - Modulo-based distribution
- Shard key extraction from WHERE clauses

### 04-sticky-writes.php
Read-after-write consistency with sticky writes.

**Topics covered:**
- Sticky write sessions
- Read-after-write consistency
- Session-based routing
- Consistency guarantees

### 05-load-balancers.php
Load balancing strategies for read replicas.

**Topics covered:**
- Round-robin load balancing
- Random load balancing
- Weighted load balancing
- Custom load balancer implementations

## Usage

```bash
# Run examples
php 01-read-write-splitting.php
php 02-basic-sharding.php
php 03-sharding-strategies.php
php 04-sticky-writes.php
php 05-load-balancers.php
```

## Architecture Patterns

- **Read/Write Splitting** - Distribute read queries across replicas
- **Sharding** - Horizontal partitioning across multiple databases
- **Load Balancing** - Distribute queries across multiple servers
- **Sticky Writes** - Ensure read-after-write consistency

## Related Examples

- [Connection Pooling](../03-advanced/01-connection-pooling.php) - Managing multiple connections
- [Performance](../07-performance/) - Performance optimization techniques
