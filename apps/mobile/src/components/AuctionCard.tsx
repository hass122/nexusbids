import React, { useState, useEffect } from 'react';
import { View, Text, StyleSheet, Image, TouchableOpacity } from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import { Colors } from '../theme/colors';

interface AuctionCardProps {
  auction: {
    auction_id: number;
    title: string;
    image?: string;
    category_name?: string;
    current_bid?: number;
    starting_price?: number;
    bid_count?: number;
    end_time: string;
    is_watched?: boolean;
    status?: string;
  };
  onPress: () => void;
  onBidPress?: () => void;
  onWatchPress?: () => void;
}

export const AuctionCard: React.FC<AuctionCardProps> = ({
  auction,
  onPress,
  onBidPress,
  onWatchPress,
}) => {
  const [timeLeft, setTimeLeft] = useState('');
  const [isEndingSoon, setIsEndingSoon] = useState(false);
  const [isEnded, setIsEnded] = useState(false);

  useEffect(() => {
    const updateCountdown = () => {
      const diff = new Date(auction.end_time).getTime() - Date.now();
      if (diff <= 0) {
        setTimeLeft('ENDED');
        setIsEndingSoon(false);
        setIsEnded(true);
        return;
      }

      setIsEnded(false);
      const hours = Math.floor(diff / (1000 * 60 * 60));
      const mins = Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60));
      const secs = Math.floor((diff % (1000 * 60)) / 1000);

      setIsEndingSoon(hours < 2);

      if (hours > 24) {
        const days = Math.floor(hours / 24);
        setTimeLeft(`${days}d ${hours % 24}h`);
      } else {
        setTimeLeft(
          `${hours.toString().padStart(2, '0')}:${mins.toString().padStart(2, '0')}:${secs.toString().padStart(2, '0')}`
        );
      }
    };

    updateCountdown();
    const interval = setInterval(updateCountdown, 1000);
    return () => clearInterval(interval);
  }, [auction.end_time]);

  const price = auction.current_bid || auction.starting_price || 0;

  return (
    <TouchableOpacity style={styles.card} activeOpacity={0.9} onPress={onPress}>
      {/* 16:10 Aspect Ratio Image Container */}
      <View style={styles.imageContainer}>
        <Image
          source={{
            uri:
              auction.image ||
              'https://images.unsplash.com/photo-1523275335684-37898b6baf30?w=600',
          }}
          style={styles.image}
        />

        {/* Top-Left: Category Tag */}
        <View style={styles.categoryBadge}>
          <Text style={styles.categoryText}>
            {(auction.category_name || 'LOT').toUpperCase()}
          </Text>
        </View>

        {/* Top-Right: Vector Heart Watchlist Button */}
        <TouchableOpacity
          style={styles.watchBadge}
          onPress={onWatchPress}
          activeOpacity={0.8}
          accessibilityLabel="Toggle Watchlist"
        >
          <Ionicons
            name={auction.is_watched ? 'heart' : 'heart-outline'}
            size={18}
            color={auction.is_watched ? Colors.danger : '#ffffff'}
          />
        </TouchableOpacity>

        {/* Bottom-Left: Live Countdown Ticker */}
        <View
          style={[
            styles.timerBadge,
            isEnded
              ? styles.timerEnded
              : isEndingSoon
              ? styles.timerBadgeUrgent
              : styles.timerActive,
          ]}
        >
          <Ionicons
            name={isEnded ? 'checkmark-circle-outline' : 'time-outline'}
            size={12}
            color={isEnded ? Colors.textDim : isEndingSoon ? '#ffffff' : Colors.primaryLight}
            style={{ marginRight: 4 }}
          />
          <Text
            style={[
              styles.timerText,
              isEndingSoon && styles.timerTextUrgent,
              isEnded && styles.timerTextEnded,
            ]}
          >
            {timeLeft}
          </Text>
        </View>
      </View>

      {/* Details Section */}
      <View style={styles.details}>
        <Text style={styles.title} numberOfLines={2}>
          {auction.title}
        </Text>

        <View style={styles.footer}>
          <View style={styles.priceCol}>
            <Text style={styles.priceLabel}>Current Bid</Text>
            <Text style={styles.priceValue}>PKR {Number(price).toLocaleString()}</Text>
            <View style={styles.bidsRow}>
              <Ionicons name="trending-up-outline" size={11} color={Colors.textDim} style={{ marginRight: 3 }} />
              <Text style={styles.bidCount}>{auction.bid_count || 0} bids placed</Text>
            </View>
          </View>

          {onBidPress && !isEnded && (
            <TouchableOpacity style={styles.bidBtn} onPress={onBidPress} activeOpacity={0.85}>
              <Text style={styles.bidBtnText}>Bid</Text>
              <Ionicons name="arrow-forward" size={13} color="#ffffff" style={{ marginLeft: 4 }} />
            </TouchableOpacity>
          )}
        </View>
      </View>
    </TouchableOpacity>
  );
};

const styles = StyleSheet.create({
  card: {
    backgroundColor: Colors.surface,
    borderRadius: 16,
    borderWidth: 1,
    borderColor: Colors.borderSubtle,
    marginBottom: 16,
    overflow: 'hidden',
    shadowColor: '#000000',
    shadowOffset: { width: 0, height: 4 },
    shadowOpacity: 0.25,
    shadowRadius: 8,
    elevation: 3,
  },
  imageContainer: {
    height: 185,
    width: '100%',
    position: 'relative',
    backgroundColor: Colors.surfaceLight,
  },
  image: {
    width: '100%',
    height: '100%',
    resizeMode: 'cover',
  },
  categoryBadge: {
    position: 'absolute',
    top: 12,
    left: 12,
    backgroundColor: 'rgba(3, 7, 18, 0.75)',
    paddingHorizontal: 9,
    paddingVertical: 4,
    borderRadius: 8,
    borderWidth: 1,
    borderColor: 'rgba(255, 255, 255, 0.1)',
  },
  categoryText: {
    fontSize: 10,
    fontWeight: '800',
    color: Colors.primaryLight,
    letterSpacing: 0.5,
  },
  watchBadge: {
    position: 'absolute',
    top: 12,
    right: 12,
    width: 32,
    height: 32,
    borderRadius: 16,
    backgroundColor: 'rgba(3, 7, 18, 0.75)',
    alignItems: 'center',
    justifyContent: 'center',
    borderWidth: 1,
    borderColor: 'rgba(255, 255, 255, 0.1)',
  },
  timerBadge: {
    position: 'absolute',
    bottom: 12,
    left: 12,
    flexDirection: 'row',
    alignItems: 'center',
    paddingHorizontal: 9,
    paddingVertical: 4.5,
    borderRadius: 8,
    borderWidth: 1,
  },
  timerActive: {
    backgroundColor: 'rgba(3, 7, 18, 0.82)',
    borderColor: 'rgba(99, 102, 241, 0.35)',
  },
  timerBadgeUrgent: {
    backgroundColor: Colors.danger,
    borderColor: Colors.danger,
  },
  timerEnded: {
    backgroundColor: 'rgba(3, 7, 18, 0.82)',
    borderColor: Colors.borderSubtle,
  },
  timerText: {
    fontSize: 11,
    fontWeight: '800',
    color: Colors.text,
    letterSpacing: 0.3,
  },
  timerTextUrgent: {
    color: '#ffffff',
  },
  timerTextEnded: {
    color: Colors.textDim,
  },
  details: {
    padding: 14,
  },
  title: {
    fontSize: 15,
    fontWeight: '700',
    color: Colors.text,
    lineHeight: 20,
    marginBottom: 10,
  },
  footer: {
    flexDirection: 'row',
    alignItems: 'flex-end',
    justifyContent: 'space-between',
    paddingTop: 8,
    borderTopWidth: 1,
    borderTopColor: 'rgba(255, 255, 255, 0.05)',
  },
  priceCol: {
    flex: 1,
  },
  priceLabel: {
    fontSize: 10,
    fontWeight: '600',
    color: Colors.textDim,
    textTransform: 'uppercase',
    letterSpacing: 0.5,
    marginBottom: 2,
  },
  priceValue: {
    fontSize: 17,
    fontWeight: '900',
    color: Colors.text,
    letterSpacing: -0.3,
  },
  bidsRow: {
    flexDirection: 'row',
    alignItems: 'center',
    marginTop: 3,
  },
  bidCount: {
    fontSize: 11,
    color: Colors.textMuted,
    fontWeight: '500',
  },
  bidBtn: {
    flexDirection: 'row',
    alignItems: 'center',
    backgroundColor: Colors.primaryDark,
    paddingHorizontal: 16,
    paddingVertical: 9,
    borderRadius: 10,
    shadowColor: Colors.primary,
    shadowOffset: { width: 0, height: 2 },
    shadowOpacity: 0.35,
    shadowRadius: 4,
    elevation: 3,
  },
  bidBtnText: {
    fontSize: 13,
    fontWeight: '800',
    color: '#ffffff',
  },
});
