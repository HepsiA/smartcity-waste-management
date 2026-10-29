import java.util.*;

public class DijkstraRouter {

    static class Node implements Comparable<Node> {
        int id;
        double distance;

        Node(int id, double distance) {
            this.id = id;
            this.distance = distance;
        }

        @Override
        public int compareTo(Node other) {
            return Double.compare(this.distance, other.distance);
        }
    }

    static class Edge {
        int target;
        double weight;

        Edge(int target, double weight) {
            this.target = target;
            this.weight = weight;
        }
    }

    // Dijkstra's Algorithm
    public static Map<Integer, Double> dijkstra(Map<Integer, List<Edge>> graph, int startNode) {
        Map<Integer, Double> distances = new HashMap<>();
        PriorityQueue<Node> pq = new PriorityQueue<>();

        for (int node : graph.keySet()) {
            distances.put(node, Double.MAX_VALUE);
        }

        distances.put(startNode, 0.0);
        pq.add(new Node(startNode, 0.0));

        while (!pq.isEmpty()) {
            Node current = pq.poll();
            int u = current.id;

            if (current.distance > distances.get(u)) continue;

            if (graph.containsKey(u)) {
                for (Edge edge : graph.get(u)) {
                    int v = edge.target;
                    double newDist = distances.get(u) + edge.weight;

                    if (newDist < distances.getOrDefault(v, Double.MAX_VALUE)) {
                        distances.put(v, newDist);
                        pq.add(new Node(v, newDist));
                    }
                }
            }
        }
        return distances;
    }

    // Haversine Formula (Distance Calculation)
    public static double calculateDistance(double lat1, double lon1, double lat2, double lon2) {
        final int R = 6371;
        double latDistance = Math.toRadians(lat2 - lat1);
        double lonDistance = Math.toRadians(lon2 - lon1);
        double a = Math.sin(latDistance / 2) * Math.sin(latDistance / 2)
                + Math.cos(Math.toRadians(lat1)) * Math.cos(Math.toRadians(lat2))
                * Math.sin(lonDistance / 2) * Math.sin(lonDistance / 2);
        double c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
        return R * c;
    }

    public static void main(String[] args) {
        if (args.length == 0) return;

        String[] bins = args[0].split(";");
        Map<Integer, List<Edge>> graph = new HashMap<>();
        Map<Integer, double[]> coords = new HashMap<>();
        Map<Integer, Integer> priorities = new HashMap<>();

        List<Integer> allIds = new ArrayList<>();
        for (String b : bins) {
            if (b.trim().isEmpty()) continue;
            String[] p = b.split(",");
            int id = Integer.parseInt(p[0]);
            int priority = Integer.parseInt(p[2]); // weight (p[1]) not used in route calculation directly here
            double lat = Double.parseDouble(p[3]);
            double lon = Double.parseDouble(p[4]);

            coords.put(id, new double[]{lat, lon});
            priorities.put(id, priority);
            allIds.add(id);
            graph.putIfAbsent(id, new ArrayList<>());
        }

        // Build Graph Edges
        for (int i = 0; i < allIds.size(); i++) {
            for (int j = i + 1; j < allIds.size(); j++) {
                int id1 = allIds.get(i);
                int id2 = allIds.get(j);
                double[] c1 = coords.get(id1);
                double[] c2 = coords.get(id2);
                double dist = calculateDistance(c1[0], c1[1], c2[0], c2[1]);

                graph.get(id1).add(new Edge(id2, dist));
                graph.get(id2).add(new Edge(id1, dist));
            }
        }

        // Sort by Priority
        allIds.sort(Comparator.comparingInt(priorities::get));

        if (allIds.isEmpty()) return;

        int currentStartNode = allIds.get(0);
        StringBuilder routeResult = new StringBuilder();
        Set<Integer> visited = new HashSet<>();

        while (visited.size() < allIds.size()) {
            Map<Integer, Double> distances = dijkstra(graph, currentStartNode);
            int nextTarget = -1;
            double minDistance = Double.MAX_VALUE;

            for (int id : allIds) {
                if (!visited.contains(id)) {
                    double dist = distances.getOrDefault(id, Double.MAX_VALUE);
                    if (dist < minDistance) {
                        minDistance = dist;
                        nextTarget = id;
                    }
                }
            }

            if (nextTarget == -1) break;
            
            routeResult.append(nextTarget).append(",");
            visited.add(nextTarget);
            currentStartNode = nextTarget;
        }

        System.out.println(routeResult.toString());
    }
}