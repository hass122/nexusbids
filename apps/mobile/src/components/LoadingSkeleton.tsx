import React from 'react';
import { View, StyleSheet, ActivityIndicator, Text } from 'react-native';
import { Colors } from '../theme/colors';

interface LoadingSkeletonProps {
  message?: string;
  count?: number;
}

export const LoadingSkeleton: React.FC<LoadingSkeletonProps> = ({
  message = 'Loading live auctions...',
  count = 3,
}) => {
  return (
    <View style={styles.container}>
      <ActivityIndicator size="large" color={Colors.primary} style={styles.spinner} />
      <Text style={styles.message}>{message}</Text>

      {Array.from({ length: count }).map((_, index) => (
        <View key={index} style={styles.cardSkeleton}>
          <View style={styles.imageSkeleton} />
          <View style={styles.contentSkeleton}>
            <View style={styles.lineShort} />
            <View style={styles.lineLong} />
            <View style={styles.footerSkeleton}>
              <View style={styles.priceSkeleton} />
              <View style={styles.btnSkeleton} />
            </View>
          </View>
        </View>
      ))}
    </View>
  );
};

const styles = StyleSheet.create({
  container: {
    padding: 16,
    alignItems: 'center',
  },
  spinner: {
    marginBottom: 8,
  },
  message: {
    fontSize: 13,
    color: Colors.textMuted,
    marginBottom: 20,
    fontWeight: '600',
  },
  cardSkeleton: {
    width: '100%',
    backgroundColor: Colors.card,
    borderRadius: 16,
    marginBottom: 16,
    overflow: 'hidden',
    borderWidth: 1,
    borderColor: Colors.border,
  },
  imageSkeleton: {
    width: '100%',
    height: 160,
    backgroundColor: Colors.surfaceLight,
  },
  contentSkeleton: {
    padding: 14,
  },
  lineShort: {
    width: '35%',
    height: 12,
    backgroundColor: Colors.surfaceLight,
    borderRadius: 6,
    marginBottom: 10,
  },
  lineLong: {
    width: '85%',
    height: 16,
    backgroundColor: Colors.surfaceLight,
    borderRadius: 8,
    marginBottom: 16,
  },
  footerSkeleton: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
  },
  priceSkeleton: {
    width: '40%',
    height: 20,
    backgroundColor: Colors.surfaceLight,
    borderRadius: 6,
  },
  btnSkeleton: {
    width: 60,
    height: 30,
    backgroundColor: Colors.surfaceLight,
    borderRadius: 8,
  },
});
