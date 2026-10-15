import React from 'react';
import { View, Text, StyleSheet } from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import { Colors } from '../theme/colors';

interface Props {
  isOffline: boolean;
  onRetry?: () => void;
}

export const OfflineBanner: React.FC<Props> = ({ isOffline }) => {
  if (!isOffline) return null;

  return (
    <View style={styles.banner}>
      <Ionicons name="cloud-offline-outline" size={14} color="#fecaca" style={styles.icon} />
      <Text style={styles.text}>Offline Mode: Realtime bid streams and checkout are paused</Text>
    </View>
  );
};

const styles = StyleSheet.create({
  banner: {
    backgroundColor: '#7f1d1d',
    paddingVertical: 7,
    paddingHorizontal: 14,
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    borderBottomWidth: 1,
    borderBottomColor: 'rgba(254, 202, 202, 0.2)',
  },
  icon: {
    marginRight: 8,
  },
  text: {
    color: '#fecaca',
    fontSize: 11,
    fontWeight: '700',
    letterSpacing: -0.1,
  },
});
