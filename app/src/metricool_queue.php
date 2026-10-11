<?php
/**
 * Metricool social queue: enqueues published content for AI-powered social publishing.
 * A Claude Routine reads the queue every 10 minutes, generates copy per network, and
 * schedules posts via Metricool MCP.
 */

function socialQueueEnqueueIfEnabled(int $userId, string $type, array $data): void {
    $stmt = getDB()->prepare('SELECT metricool_blog_id, metricool_networks, metricool_auto_social FROM profiles WHERE user_id=?');
    $stmt->execute([$userId]);
    $profile = $stmt->fetch();
    if (!$profile || empty($profile['metricool_auto_social']) || !$profile['metricool_blog_id']) {
        return;
    }
    $ins = getDB()->prepare(
        'INSERT INTO social_queue (user_id, content_type, content_id, title, body, image_url, public_url) VALUES (?,?,?,?,?,?,?)'
    );
    $ins->execute([
        $userId,
        $type,
        (int) ($data['content_id'] ?? 0),
        $data['title'] ?? null,
        $data['body'] ?? null,
        $data['image_url'] ?? null,
        $data['public_url'] ?? '',
    ]);
}
