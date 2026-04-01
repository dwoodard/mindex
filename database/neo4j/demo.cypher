// ─── Demo seed data for Mindex ───────────────────────────────────────────────
// Reflects the real NodeType / RelationType / Origin enums from the project plan.
// Run via: docker compose exec neo4j cypher-shell -u neo4j -p neo4jtest < database/neo4j/demo.cypher

// Clear existing demo data
MATCH (n) DETACH DELETE n;

// ─── Nodes ────────────────────────────────────────────────────────────────────

MERGE (u:Person {id: 'person_dustin'})
SET u += {
  label: 'Dustin',
  type: 'Person',
  origin: 'system',
  confidence: 1.0,
  mention_count: 1,
  anchored: true,
  decay_rate: 0.0,
  properties: '{"role":"owner","note":"the user — all captured thoughts originate here"}'
};

MERGE (p1:Project {id: 'project_mindex'})
SET p1 += {
  label: 'Mindex',
  type: 'Project',
  origin: 'user',
  confidence: 0.95,
  mention_count: 3,
  anchored: true,
  decay_rate: 0.01,
  properties: '{"status":"active","stack":"Laravel + Neo4j + Vue"}'
};

MERGE (i1:Idea {id: 'idea_retrieve_before_write'})
SET i1 += {
  label: 'Retrieve before write prevents duplicate nodes',
  type: 'Idea',
  origin: 'user',
  confidence: 0.9,
  mention_count: 2,
  anchored: false,
  decay_rate: 0.02,
  properties: '{"context":"pipeline design","priority":"critical"}'
};

MERGE (b1:Belief {id: 'belief_friction_kills_capture'})
SET b1 += {
  label: 'Zero friction is the only way capture actually happens',
  type: 'Belief',
  origin: 'user',
  confidence: 0.85,
  mention_count: 1,
  anchored: false,
  decay_rate: 0.02,
  properties: '{"formed":"early planning","strength":"strong"}'
};

MERGE (b2:Belief {id: 'belief_ai_should_not_own_shape'})
SET b2 += {
  label: 'The AI should own meaning, not schema shape',
  type: 'Belief',
  origin: 'user',
  confidence: 0.88,
  mention_count: 2,
  anchored: false,
  decay_rate: 0.02,
  properties: '{"context":"Prism / schema enforcement"}'
};

MERGE (q1:Question {id: 'question_prism_vs_ai_sdk'})
SET q1 += {
  label: 'Does Laravel 13 AI SDK support structured output enforcement like Prism?',
  type: 'Question',
  origin: 'user',
  confidence: 0.7,
  mention_count: 1,
  anchored: false,
  decay_rate: 0.03,
  properties: '{"status":"open","blocking":"ExtractionService implementation"}'
};

MERGE (pref1:Preference {id: 'pref_graph_over_flat'})
SET pref1 += {
  label: 'Prefers graph databases over flat document stores for relational knowledge',
  type: 'Preference',
  origin: 'inferred',
  confidence: 0.75,
  mention_count: 1,
  anchored: false,
  decay_rate: 0.02,
  properties: '{"domain":"data architecture"}'
};

MERGE (ev1:Event {id: 'event_mindex_kickoff'})
SET ev1 += {
  label: 'Mindex project kickoff',
  type: 'Event',
  origin: 'system',
  confidence: 1.0,
  mention_count: 1,
  anchored: true,
  decay_rate: 0.0,
  properties: '{"date":"2026-03-31"}'
};

MERGE (res1:Resource {id: 'resource_prism_php'})
SET res1 += {
  label: 'Prism PHP (echolabs/prism)',
  type: 'Resource',
  origin: 'user',
  confidence: 0.95,
  mention_count: 2,
  anchored: false,
  decay_rate: 0.01,
  properties: '{"purpose":"structured AI output","url":"https://github.com/echolabs/prism"}'
};

// ─── Relationships ────────────────────────────────────────────────────────────

MATCH (u:Person {id: 'person_dustin'}), (p1:Project {id: 'project_mindex'})
MERGE (u)-[:ORIGINATED {origin: 'system', strength: 1.0, reason: 'user created this project'}]->(p1);

MATCH (u:Person {id: 'person_dustin'}), (b1:Belief {id: 'belief_friction_kills_capture'})
MERGE (u)-[:ORIGINATED {origin: 'user', strength: 0.85, reason: 'user stated this directly'}]->(b1);

MATCH (u:Person {id: 'person_dustin'}), (b2:Belief {id: 'belief_ai_should_not_own_shape'})
MERGE (u)-[:ORIGINATED {origin: 'user', strength: 0.88, reason: 'user stated this directly'}]->(b2);

MATCH (p1:Project {id: 'project_mindex'}), (i1:Idea {id: 'idea_retrieve_before_write'})
MERGE (p1)-[:BUILT_ON {origin: 'user', strength: 0.9, reason: 'core pipeline principle'}]->(i1);

MATCH (b1:Belief {id: 'belief_friction_kills_capture'}), (p1:Project {id: 'project_mindex'})
MERGE (b1)-[:ENABLES {origin: 'inferred', strength: 0.8, reason: 'belief motivates zero-friction capture design'}]->(p1);

MATCH (b2:Belief {id: 'belief_ai_should_not_own_shape'}), (res1:Resource {id: 'resource_prism_php'})
MERGE (b2)-[:ENABLES {origin: 'inferred', strength: 0.85, reason: 'Prism enforces schema so AI cannot deviate'}]->(res1);

MATCH (p1:Project {id: 'project_mindex'}), (q1:Question {id: 'question_prism_vs_ai_sdk'})
MERGE (p1)-[:HAS_QUESTION {origin: 'user', strength: 0.7, reason: 'open question blocking ExtractionService build'}]->(q1);

MATCH (u:Person {id: 'person_dustin'}), (pref1:Preference {id: 'pref_graph_over_flat'})
MERGE (u)-[:PREFERS {origin: 'inferred', strength: 0.75, reason: 'chose Neo4j as primary store over Postgres or MongoDB'}]->(pref1);

MATCH (ev1:Event {id: 'event_mindex_kickoff'}), (p1:Project {id: 'project_mindex'})
MERGE (ev1)-[:MENTIONS {origin: 'system', strength: 1.0, reason: 'event created this project'}]->(p1);

MATCH (i1:Idea {id: 'idea_retrieve_before_write'}), (b2:Belief {id: 'belief_ai_should_not_own_shape'})
MERGE (i1)-[:REINFORCES {origin: 'inferred', strength: 0.7, reason: 'both reflect keeping AI constrained to its lane'}]->(b2);
