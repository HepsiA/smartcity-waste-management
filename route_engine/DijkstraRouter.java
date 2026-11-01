import java.util.*;

public class DijkstraRouter {
    
    static class Node implements Comparable<Node> {
        int id; double weight; int priority; 
        
        Node(int id, double weight, int priority) { 
            this.id = id; this.weight = weight; this.priority = priority;
        }
        
        @Override
        public int compareTo(Node other) {
            if (this.priority != other.priority) {
                return Integer.compare(this.priority, other.priority);
            }
            return Double.compare(other.weight, this.weight); 
        }
    }

    public static void main(String[] args) {
        if (args.length == 0) return;
        String[] parts = args[0].split(";");
        PriorityQueue<Node> pq = new PriorityQueue<>();
        
        for (int i = 0; i < parts.length; i++) {
            if (parts[i].trim().isEmpty()) continue;
            String[] p = parts[i].split(",");
            int id = Integer.parseInt(p[0]);
            double weight = Double.parseDouble(p[1]);
            int priority = Integer.parseInt(p[2]);
            pq.add(new Node(id, weight, priority));
        }

        if(!pq.isEmpty()) {
            Node bestNext = pq.poll();
            System.out.println(bestNext.id);
        } else {
            System.out.println("-1");
        }
    }
}